<?php

/**
 * Dead ends: places where the UI offers an action the page then refuses, with
 * no way to satisfy it from where the user is standing.
 *
 * The reported case was "Jadikan Agent" on an interaction: the button is right
 * there, pressing it says "lengkapi nama asli dan nomor WhatsApp", and that
 * page has no field for either.
 */

use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Interaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/** An interaction whose contact has neither a real name nor a number. */
function bareContactInteraction(): Interaction
{
    $interaction = instagramComment();

    $contact = Contact::create([
        'code' => 'UT-TEST01',
        'display_name' => 'Luthfi Erys (uji)',
        'status' => 'non_agent',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    ContactIdentity::create([
        'contact_id' => $contact->id,
        'channel' => 'instagram',
        'handle' => 'uideadend_test_handle',
    ]);

    $interaction->forceFill(['contact_id' => $contact->id])->save();

    return $interaction->refresh();
}

/* -----------------------------------------------------------------
 | The reported bug
 * ----------------------------------------------------------------- */

it('offers the whole contact form from an interaction', function () {
    $interaction = bareContactInteraction();

    $response = $this->actingAs(admin())->get(route('interactions.show', $interaction));

    $response->assertOk()->assertSee('Jadikan Agent');

    // Not just the two required fields — the same full form the contact page
    // shows, so an operator never has to go hunting for another screen.
    foreach (['full_name', 'phone', 'email', 'owner_id', 'region_id', 'address_detail', 'potential_score', 'notes'] as $field) {
        $response->assertSee('name="'.$field.'"', false);
    }

    $response->assertSee('Simpan &amp; Jadikan Agent', false)
        ->assertSee('Semua kolom boleh dikosongkan', false);
});

it('completes the contact and promotes in one submit', function () {
    $interaction = bareContactInteraction();
    $contact = $interaction->contact;

    $this->actingAs(admin())
        ->post(route('contacts.promote', $contact), [
            'full_name' => 'Luthfi Erys Saputra',
            'phone' => '081234567899',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $contact->refresh();

    expect($contact->full_name)->toBe('Luthfi Erys Saputra')
        ->and($contact->phone_e164)->toBe('6281234567899')
        ->and($contact->isAgent())->toBeTrue()
        ->and($contact->agent_code)->not->toBeNull();
});

it('still refuses to promote when the fields are left empty, and says where', function () {
    $interaction = bareContactInteraction();

    $this->actingAs(admin())
        ->post(route('contacts.promote', $interaction->contact))
        ->assertSessionHasErrors('full_name');

    expect($interaction->contact->refresh()->isAgent())->toBeFalse();
});

it('rejects an unusable phone number when promoting', function () {
    $interaction = bareContactInteraction();

    $this->actingAs(admin())
        ->post(route('contacts.promote', $interaction->contact), [
            'full_name' => 'Nama Lengkap',
            'phone' => '12345',
        ])
        ->assertSessionHasErrors('phone');

    expect($interaction->contact->refresh()->isAgent())->toBeFalse();
});

it('promotes without asking again when the contact is already complete', function () {
    $interaction = bareContactInteraction();

    $interaction->contact->forceFill([
        'full_name' => 'Sudah Lengkap',
        'phone_e164' => '6281200009999',
    ])->save();

    $this->actingAs(admin())
        ->post(route('contacts.promote', $interaction->contact))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($interaction->contact->refresh()->isAgent())->toBeTrue();
});

/* -----------------------------------------------------------------
 | The data-loss bug the fix above would otherwise have caused
 * ----------------------------------------------------------------- */

it('does not wipe the fields a partial contact form left out', function () {
    $contact = Contact::create([
        'code' => 'UT-TEST02',
        'display_name' => 'Kontak Lengkap',
        'full_name' => 'Nama Asli',
        'email' => 'lengkap@example.com',
        'phone_e164' => '6281200001111',
        'address_detail' => 'Jl. Contoh No. 1',
        'notes' => 'Catatan penting yang tidak boleh hilang.',
        'potential_score' => 70,
        'status' => 'non_agent',
    ]);

    // A form that only carries the name — as the compact panels do.
    $this->actingAs(admin())
        ->put(route('contacts.update', $contact), ['full_name' => 'Nama Diperbarui'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $contact->refresh();

    expect($contact->full_name)->toBe('Nama Diperbarui')
        // Everything the form did not carry must survive untouched.
        ->and($contact->email)->toBe('lengkap@example.com')
        ->and($contact->phone_e164)->toBe('6281200001111')
        ->and($contact->address_detail)->toBe('Jl. Contoh No. 1')
        ->and($contact->notes)->toBe('Catatan penting yang tidak boleh hilang.')
        ->and($contact->potential_score)->toBe(70);
});

it('still lets a full form clear a field on purpose', function () {
    $contact = Contact::create([
        'code' => 'UT-TEST03',
        'display_name' => 'Kontak',
        'full_name' => 'Nama Asli',
        'notes' => 'Catatan lama',
        'status' => 'non_agent',
    ]);

    // The field is present but empty — that is a deliberate clear.
    $this->actingAs(admin())
        ->put(route('contacts.update', $contact), ['full_name' => 'Nama Asli', 'notes' => ''])
        ->assertRedirect();

    expect($contact->refresh()->notes)->toBeNull();
});

/* -----------------------------------------------------------------
 | "Run an artisan command" is not an instruction for an operator
 * ----------------------------------------------------------------- */

it('offers a button instead of a terminal command when a comment has no contact', function () {
    $interaction = instagramComment();
    $interaction->forceFill(['contact_id' => null])->save();

    $response = $this->actingAs(admin())->get(route('interactions.show', $interaction->refresh()));

    $response->assertOk()
        ->assertDontSee('php artisan')
        ->assertSee(route('interactions.resolveContact', $interaction), false);
});

it('matches a comment to a contact from the page', function () {
    $interaction = instagramComment();
    $interaction->forceFill(['contact_id' => null])->save();

    $this->actingAs(admin())
        ->post(route('interactions.resolveContact', $interaction->refresh()))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($interaction->refresh()->contact_id)->not->toBeNull()
        ->and($interaction->contact->identities->pluck('handle'))->toContain('mahasiswa123');
});

/* -----------------------------------------------------------------
 | Feedback that never arrives
 |
 | The toast partial was seeded only from session('toast'). Every
 | ->with('success', ...) in the app therefore flashed into the void: the
 | action worked, and the user was told nothing.
 * ----------------------------------------------------------------- */

it('shows a toast for the simple success flash key', function () {
    $ticket = app(\App\Services\Tickets\TicketService::class)
        ->createManual(['subject' => 'Tiket uji toast'], admin());

    // from(): these actions redirect with back(), and without a referer that
    // bounces via "/" — an extra request that consumes the flash before the
    // page it was meant for ever renders.
    $response = $this->actingAs(admin())
        ->from(route('tickets.show', $ticket))
        ->followingRedirects()
        ->post(route('tickets.flag', $ticket), ['flag' => 'lead']);

    $response->assertOk()->assertSee('Tiket ditandai sebagai Lead.');
});

it('shows a toast for the structured toast flash key', function () {
    // The older convention has to keep working — half the app still uses it.
    $response = $this->actingAs(admin())
        ->followingRedirects()
        ->withSession(['toast' => ['message' => 'Pesan gaya lama', 'type' => 'success']])
        ->get(route('dashboard'));

    $response->assertOk()->assertSee('Pesan gaya lama');
});

it('shows a toast for the info flash key', function () {
    $response = $this->actingAs(admin())
        ->followingRedirects()
        ->withSession(['info' => 'Sekadar pemberitahuan'])
        ->get(route('dashboard'));

    $response->assertOk()->assertSee('Sekadar pemberitahuan');
});

it('tells the operator when an assignment actually happened', function () {
    $student = \App\Models\Student::create([
        'nim' => 'TOAST00001',
        'nama' => 'Mahasiswa Toast',
        'kategori_masalah' => 'ongoing_tidak_registrasi',
    ]);

    $operator = operatorNamed('toast-op@test.local');

    $response = $this->actingAs(admin())
        ->from(route('students.unsigned'))
        ->followingRedirects()
        ->post(route('students.assign.selected'), [
            'students' => [$student->id],
            'operator_id' => $operator->id,
        ]);

    $response->assertOk()->assertSee('ditugaskan ke '.$operator->name);
});

/* -----------------------------------------------------------------
 | One form, three buttons
 |
 | Saving must never demand a complete record — an operator who learned one
 | thing saves that one thing. Only "Jadikan Agent" adds a requirement.
 * ----------------------------------------------------------------- */

it('saves a note on its own, with every other field left blank', function () {
    $interaction = bareContactInteraction();
    $contact = $interaction->contact;

    $this->actingAs(admin())
        ->from(route('interactions.show', $interaction))
        ->put(route('contacts.update', $contact), [
            'intent' => 'save',
            'notes' => 'Baru tanya-tanya, minta dihubungi sore.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($contact->refresh()->notes)->toBe('Baru tanya-tanya, minta dihubungi sore.')
        ->and($contact->full_name)->toBeNull()
        ->and($contact->status)->toBe(\App\Enums\ContactStatus::NonAgent);
});

it('saves a partly filled form without complaining', function () {
    $contact = bareContactInteraction()->contact;

    $this->actingAs(admin())
        ->put(route('contacts.update', $contact), [
            'intent' => 'save',
            'phone' => $number = '0812-5555-'.random_int(1000, 9999),
            'potential_score' => 40,
        ])
        ->assertSessionHasNoErrors();

    $contact->refresh();

    expect($contact->phone_e164)->toBe(\App\Support\PhoneNumber::normalize($number))
        ->and($contact->potential_score)->toBe(40)
        ->and($contact->full_name)->toBeNull();
});

it('saves and promotes in one press when the form is complete', function () {
    $contact = bareContactInteraction()->contact;

    $this->actingAs(admin())
        ->put(route('contacts.update', $contact), [
            'intent' => 'promote',
            'full_name' => 'Uji Coba Sistem',
            'phone' => '0812-5556-'.random_int(1000, 9999),
            'notes' => 'Siap jadi agent.',
        ])
        ->assertSessionHasNoErrors();

    $contact->refresh();

    expect($contact->isAgent())->toBeTrue()
        ->and($contact->full_name)->toBe('Uji Coba Sistem')
        ->and($contact->notes)->toBe('Siap jadi agent.')
        ->and($contact->agent_code)->not->toBeNull();
});

it('keeps what was typed when promoting is refused', function () {
    $contact = bareContactInteraction()->contact;

    $this->actingAs(admin())
        ->put(route('contacts.update', $contact), [
            'intent' => 'promote',
            'notes' => 'Catatan ini tidak boleh hilang.',
            // No name, no number.
        ])
        ->assertSessionHasErrors(['full_name', 'phone']);

    // The save happened first, so the refusal costs the operator nothing.
    expect($contact->refresh()->notes)->toBe('Catatan ini tidak boleh hilang.')
        ->and($contact->isAgent())->toBeFalse();
});

it('marks a candidate from the same form', function () {
    $contact = bareContactInteraction()->contact;

    $this->actingAs(admin())
        ->put(route('contacts.update', $contact), [
            'intent' => 'candidate',
            'notes' => 'Menarik, pantau dulu.',
        ])
        ->assertSessionHasNoErrors();

    expect($contact->refresh()->status)->toBe(\App\Enums\ContactStatus::Candidate)
        ->and($contact->notes)->toBe('Menarik, pantau dulu.');
});

it('does not promote for a user who may edit but not appoint agents', function () {
    $contact = bareContactInteraction()->contact;

    // PIC holds neither ManageContacts nor ManageAgents.
    $pic = operatorNamed('form-pic@test.local', \App\Enums\RoleName::Pic);

    $this->actingAs($pic)
        ->put(route('contacts.update', $contact), [
            'intent' => 'promote',
            'full_name' => 'Tidak Berhak',
            'phone' => '0812-5557-'.random_int(1000, 9999),
        ])
        ->assertForbidden();

    expect($contact->refresh()->isAgent())->toBeFalse();
});

/* -----------------------------------------------------------------
 | Tabs that explain themselves
 * ----------------------------------------------------------------- */

it('spells out what each inbox tab means', function () {
    foreach (\App\Http\Controllers\InteractionController::TABS as $key => $tab) {
        // label, icon, tone, description — the fourth is what makes the tab
        // readable to someone coming back after a week.
        expect($tab)->toHaveCount(4)
            ->and($tab[3])->toBeString()->not->toBeEmpty();

        $this->actingAs(admin())
            ->get(route('interactions.index', ['tab' => $key]))
            ->assertOk()
            ->assertSee($tab[3]);
    }
});

it('says that Tugas Saya means assigned to me', function () {
    $this->actingAs(admin())
        ->get(route('interactions.index', ['tab' => 'mine']))
        ->assertOk()
        ->assertSee('ditugaskan kepada Anda');
});

/* -----------------------------------------------------------------
 | A connection test that succeeds must not leave the page saying
 | the opposite.
 * ----------------------------------------------------------------- */

it('corrects a stale expiry when the token proves it still works', function () {
    $account = \App\Models\SocialAccount::create([
        'platform' => 'instagram',
        'name' => 'Akun Uji Expiry',
        'username' => 'uji_expiry_'.uniqid(),
        'access_token' => 'token-uji',
        // What the bug looked like: a date in the past on a working token.
        'token_expires_at' => now()->subDays(2),
        'is_active' => true,
        'meta' => ['token_refreshed_at' => now()->subDays(30)->toIso8601String()],
    ]);

    expect($account->isTokenExpired())->toBeTrue();

    \Illuminate\Support\Facades\Http::fake([
        // The account answers, so the token is valid right now…
        'graph.instagram.com/v21.0/me*' => \Illuminate\Support\Facades\Http::response([
            'id' => '17841400000000009',
            'username' => $account->username,
            'name' => 'Akun Uji Expiry',
            'account_type' => 'MEDIA_CREATOR',
            'followers_count' => 10,
            'media_count' => 2,
        ]),
        // …and the refresh tells us how long it really has.
        'graph.instagram.com/refresh_access_token*' => \Illuminate\Support\Facades\Http::response([
            'access_token' => 'token-baru',
            'expires_in' => 5184000,   // 60 days
        ]),
    ]);

    app(\App\Services\Publishing\AccountVerifier::class)->verify($account);
    $account->refresh();

    expect($account->isTokenExpired())->toBeFalse()
        ->and($account->token_expires_at->isFuture())->toBeTrue()
        // The verify must not wipe the refresh history the scheduler relies on.
        ->and($account->meta)->toHaveKey('token_refreshed_at')
        ->and($account->meta)->toHaveKey('account_type');
});

it('says the expiry is unknown rather than expired when it cannot be refreshed', function () {
    $account = \App\Models\SocialAccount::create([
        'platform' => 'instagram',
        'name' => 'Akun Uji Unknown',
        'username' => 'uji_unknown_'.uniqid(),
        'access_token' => 'token-uji',
        'token_expires_at' => now()->subDay(),
        'is_active' => true,
    ]);

    \Illuminate\Support\Facades\Http::fake([
        'graph.instagram.com/v21.0/me*' => \Illuminate\Support\Facades\Http::response([
            'id' => '17841400000000010',
            'username' => $account->username,
            'name' => 'Akun Uji Unknown',
        ]),
        // Instagram refuses: too young to refresh.
        'graph.instagram.com/refresh_access_token*' => \Illuminate\Support\Facades\Http::response(
            ['error' => ['message' => 'can only be refreshed after 24 hours']], 400,
        ),
    ]);

    app(\App\Services\Publishing\AccountVerifier::class)->verify($account);
    $account->refresh();

    // A date we have just disproved must not survive as "expired".
    expect($account->token_expires_at)->toBeNull()
        ->and($account->isTokenExpired())->toBeFalse()
        ->and($account->meta)->toHaveKey('expiry_unknown_since');
});

it('writes relative times in Indonesian, like the rest of the app', function () {
    // An Indonesian UI showing "3 hours ago" is the locale not being set.
    expect(app()->getLocale())->toBe('id')
        ->and(now()->subHours(3)->diffForHumans())->toContain('jam')
        ->and(now()->addDays(60)->diffForHumans())->toContain('bulan');
});
