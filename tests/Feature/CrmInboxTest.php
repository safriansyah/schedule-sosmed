<?php

use App\Enums\ContactStatus;
use App\Enums\FollowUpAction;
use App\Enums\Intent;
use App\Enums\InteractionStatus;
use App\Enums\InteractionType;
use App\Enums\RoleName;
use App\Enums\Sentiment;
use App\Enums\SocialPlatform;
use App\Models\{Contact, ContactIdentity, Interaction, User};
use App\Services\AI\RuleBasedClassifier;
use App\Services\Crm\ContactResolver;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function asRole(RoleName $role): User
{
    return User::withRole($role)->firstOrFail();
}

function makeInteraction(array $attributes = []): Interaction
{
    return Interaction::create(array_merge([
        'channel' => SocialPlatform::Instagram->value,
        'type' => InteractionType::Comment->value,
        'direction' => 'inbound',
        'external_id' => 'test-'.uniqid(),
        'author_handle' => 'wargainternet',
        'text' => 'halo kak',
        'occurred_at' => now()->subHour(),
        'status' => InteractionStatus::New->value,
    ], $attributes));
}

/* -----------------------------------------------------------------
 | Phone normalisation — the thing that stops duplicate contacts
 * ----------------------------------------------------------------- */

it('normalises every way an Indonesian number gets typed', function () {
    expect(PhoneNumber::normalize('081234567890'))->toBe('6281234567890')
        ->and(PhoneNumber::normalize('+62 812-3456-7890'))->toBe('6281234567890')
        ->and(PhoneNumber::normalize('6281234567890'))->toBe('6281234567890')
        ->and(PhoneNumber::normalize('81234567890'))->toBe('6281234567890')
        ->and(PhoneNumber::normalize('062 812 3456 7890'))->toBe('6281234567890');

    // Not a mobile number, or too short to be one.
    expect(PhoneNumber::normalize('0271-555123'))->toBeNull()
        ->and(PhoneNumber::normalize('0812'))->toBeNull()
        ->and(PhoneNumber::normalize(null))->toBeNull();
});

it('pulls a WhatsApp number out of free-text comments', function () {
    expect(PhoneNumber::extractFrom('minat kak, wa aku 0812-3456-7890 ya'))->toBe('6281234567890')
        ->and(PhoneNumber::extractFrom('bagus banget'))->toBeNull();
});

/* -----------------------------------------------------------------
 | Contact resolution — one person, many accounts
 * ----------------------------------------------------------------- */

it('reuses one contact for the same handle regardless of case', function () {
    $resolver = app(ContactResolver::class);

    $first = $resolver->resolve(SocialPlatform::Instagram, 'RudiHartono');
    $again = $resolver->resolve(SocialPlatform::Instagram, '@rudihartono');

    expect($again->id)->toBe($first->id);
    expect(Contact::where('id', $first->id)->count())->toBe(1);
});

it('links a second channel to the same person', function () {
    $resolver = app(ContactResolver::class);

    $contact = $resolver->resolve(SocialPlatform::Instagram, 'dwiaryani');
    $contact->identities()->create([
        'channel' => SocialPlatform::TikTok->value,
        'handle' => 'dwiaryani_tt',
    ]);

    $viaTikTok = $resolver->resolve(SocialPlatform::TikTok, 'dwiaryani_tt');

    expect($viaTikTok->id)->toBe($contact->id);
    expect($viaTikTok->identities)->toHaveCount(2);
});

it('brings back a deleted contact instead of crashing when they return', function () {
    $resolver = app(ContactResolver::class);
    $handle = 'kembalilagi'.uniqid();

    $contact = $resolver->resolve(SocialPlatform::Instagram, $handle);
    $code = $contact->code;
    $contact->delete();   // hapus-lunak: barisnya tinggal, identitasnya juga

    // Orangnya berkomentar lagi. Sebelum diperbaiki, ini berhenti dengan
    // TypeError karena relasi kontak memulangkan null untuk baris terhapus,
    // sementara identitasnya masih memegang kunci unik (channel, handle).
    $lagi = $resolver->resolve(SocialPlatform::Instagram, $handle);

    expect($lagi)->not->toBeNull()
        // Kontak yang sama, bukan kembaran — riwayatnya ikut kembali.
        ->and($lagi->code)->toBe($code)
        ->and($lagi->trashed())->toBeFalse();
});

it('starts fresh when the contact row is gone for good', function () {
    $resolver = app(ContactResolver::class);
    $handle = 'hilangtotal'.uniqid();

    $contact = $resolver->resolve(SocialPlatform::Instagram, $handle);
    $idLama = $contact->id;
    $contact->forceDelete();   // identitasnya jadi puing

    $baru = $resolver->resolve(SocialPlatform::Instagram, $handle);

    // Puing identitas tidak boleh memblokir orang baru dengan handle sama.
    // Kodenya boleh terpakai ulang (MAX(code) ikut turun setelah hapus permanen);
    // yang penting ini record baru, bukan yang lama dihidupkan.
    expect($baru)->not->toBeNull()
        ->and($baru->id)->not->toBe($idLama);

    // Dan hanya menyisakan satu identitas, bukan dua yang bertabrakan.
    expect(ContactIdentity::where('channel', SocialPlatform::Instagram->value)
        ->where('handle', $handle)->count())->toBe(1);
});

it('captures a phone number left in a comment', function () {
    $interaction = makeInteraction([
        'author_handle' => 'calonmhs'.uniqid(),
        'text' => 'mau daftar dong, wa saya 0813-2222-1111',
    ]);

    $contact = app(ContactResolver::class)->resolveFor($interaction);

    expect($contact->phone_e164)->toBe('6281322221111');
});

/* -----------------------------------------------------------------
 | Classification — the safety net matters more than the accuracy
 * ----------------------------------------------------------------- */

it('forces urgent on a reputational attack, with no API involved', function () {
    $verdict = app(RuleBasedClassifier::class)
        ->classifyOne('kampus ini penipuan, ijazahnya palsu');

    expect($verdict->isUrgent)->toBeTrue()
        ->and($verdict->sentiment)->toBe(Sentiment::Negative)
        ->and($verdict->model)->toBe('rule')
        ->and($verdict->reason)->toContain('penipuan');
});

it('does not mistake a friendly vocative for a question', function () {
    $classifier = app(RuleBasedClassifier::class);

    expect($classifier->classifyOne('Mantap kakak')->intent)->toBe(Intent::Praise);
    expect($classifier->classifyOne('min, pendaftaran kapan?')->intent)->toBe(Intent::Question);
});

it('reads enthusiastic spelling', function () {
    $classifier = app(RuleBasedClassifier::class);

    expect($classifier->classifyOne('Makasihhhhhhh')->sentiment)->toBe(Sentiment::Positive);
    expect($classifier->classifyOne('kerennn bangetttt')->sentiment)->toBe(Sentiment::Positive);
});

it('handles negation instead of reading the word alone', function () {
    $classifier = app(RuleBasedClassifier::class);

    expect($classifier->classifyOne('pelayanannya gak bagus')->sentiment)->toBe(Sentiment::Negative);
});

it('scores enrolment intent higher than idle praise', function () {
    $classifier = app(RuleBasedClassifier::class);

    $lead = $classifier->classifyOne('mau daftar kuliah, biaya kuliahnya berapa ya?');
    $praise = $classifier->classifyOne('keren banget');

    expect($lead->leadPotential)->toBeGreaterThan($praise->leadPotential);
});

/* -----------------------------------------------------------------
 | Handling — who may do what
 * ----------------------------------------------------------------- */

it('keeps the inbox away from roles that do not handle it', function () {
    $this->actingAs(asRole(RoleName::Creative))
        ->get(route('interactions.index'))
        ->assertForbidden();

    $this->actingAs(asRole(RoleName::Operator))
        ->get(route('interactions.index'))
        ->assertOk();
});

/*
 * Follow-up on the interaction is back (repeatable, then Close), but the
 * reason it was once removed still holds: a comment followed up here AND on
 * its ticket leaves two histories that never meet. So once a ticket exists,
 * the interaction refuses follow-ups and points at the ticket instead.
 */
it('takes follow-ups on the inbox only until the comment becomes a ticket', function () {
    $interaction = makeInteraction();
    $operator = asRole(RoleName::Operator);

    $this->actingAs($operator)->post(route('interactions.followUp', $interaction), [
        'action' => 'dibalas', 'response_text' => 'Sudah dibalas lewat DM.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($interaction->followUps()->count())->toBe(1);

    app(\App\Services\Tickets\TicketService::class)->createFromInteraction($interaction->refresh(), $operator);

    $this->actingAs($operator)->post(route('interactions.followUp', $interaction), [
        'action' => 'dibalas', 'response_text' => 'Follow up kedua.',
    ])->assertSessionHasErrors('response_text');

    expect($interaction->followUps()->count())->toBe(1);
});

it('offers ticket creation and nothing else to handle with', function () {
    $interaction = makeInteraction();

    $this->actingAs(asRole(RoleName::Operator))
        ->get(route('interactions.show', $interaction))
        ->assertOk()
        // The one way forward: raise the ticket.
        ->assertSee(route('tickets.fromInteraction', $interaction), false)
        ->assertSee('Add to Ticket')
        // And no second place to record handling — no follow-up history, no
        // status select, no assignee select. Those live in the ticket.
        ->assertDontSee('Riwayat Follow-up')
        ->assertDontSee('Ditugaskan ke');
});

it('keeps the AI verdict when a human corrects it', function () {
    $interaction = makeInteraction([
        'sentiment' => Sentiment::Positive->value,
        'ai_model' => 'rule',
        'ai_classified_at' => now(),
    ]);

    $this->actingAs(asRole(RoleName::Operator))
        ->post(route('interactions.override', $interaction), [
            'sentiment' => Sentiment::Negative->value,
        ])
        ->assertRedirect();

    $interaction->refresh();

    // Both answers survive — that pair is how we measure the classifier.
    expect($interaction->sentiment)->toBe(Sentiment::Positive)
        ->and($interaction->sentiment_override)->toBe(Sentiment::Negative)
        ->and($interaction->effectiveSentiment())->toBe(Sentiment::Negative)
        ->and($interaction->wasOverridden())->toBeTrue();
});

/* -----------------------------------------------------------------
 | Agent promotion
 * ----------------------------------------------------------------- */

it('refuses to make an agent out of an incomplete record', function () {
    $contact = app(ContactResolver::class)->resolve(SocialPlatform::Instagram, 'belumlengkap'.uniqid());

    // Errors are keyed per field, not lumped under one "agent" key, so each
    // message renders under the input it is about.
    $this->actingAs(asRole(RoleName::Operator))
        ->post(route('contacts.promote', $contact))
        ->assertSessionHasErrors(['full_name', 'phone']);

    expect($contact->refresh()->status)->toBe(ContactStatus::NonAgent);
});

it('completes and promotes an incomplete record in one submit', function () {
    $contact = app(ContactResolver::class)->resolve(SocialPlatform::Instagram, 'lengkapisini'.uniqid());

    $this->actingAs(asRole(RoleName::Operator))
        ->post(route('contacts.promote', $contact), [
            'full_name' => 'Dilengkapi Saat Promosi',
            'phone' => '0812'.random_int(10000000, 99999999),
        ])
        ->assertSessionHasNoErrors();

    expect($contact->refresh()->status)->toBe(ContactStatus::Agent)
        ->and($contact->full_name)->toBe('Dilengkapi Saat Promosi');
});

it('promotes a complete record and issues an agent code', function () {
    $contact = app(ContactResolver::class)->resolve(SocialPlatform::Instagram, 'siapagent'.uniqid());
    $contact->forceFill([
        'full_name' => 'Siti Rahayu',
        'phone_e164' => '62812'.random_int(10000000, 99999999),
    ])->save();

    $operator = asRole(RoleName::Operator);

    $this->actingAs($operator)
        ->post(route('contacts.promote', $contact))
        ->assertRedirect();

    $contact->refresh();

    expect($contact->status)->toBe(ContactStatus::Agent)
        ->and($contact->agent_code)->toStartWith('AGT-')
        ->and($contact->agent_since)->not->toBeNull()
        ->and($contact->recruited_by)->toBe($operator->id);
});

it('lets only the roles with the agent permission promote', function () {
    $contact = app(ContactResolver::class)->resolve(SocialPlatform::Instagram, 'nopromote'.uniqid());
    $contact->forceFill(['full_name' => 'X', 'phone_e164' => '62813'.random_int(10000000, 99999999)])->save();

    // A PIC handles messages but does not build the agent register.
    $this->actingAs(asRole(RoleName::Pic))
        ->post(route('contacts.promote', $contact))
        ->assertForbidden();
});

it('gives every contact a readable sequential UID', function () {
    $contact = app(ContactResolver::class)->resolve(SocialPlatform::Instagram, 'uidtest'.uniqid());

    expect($contact->code)->toMatch('/^UT-\d{6}$/');
});
