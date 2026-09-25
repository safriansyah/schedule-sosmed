<?php

use App\Enums\InteractionStatus;
use App\Enums\InteractionType;
use App\Enums\RoleName;
use App\Enums\Sentiment;
use App\Enums\SocialPlatform;
use App\Models\{Contact, ContactIdentity, Interaction, User};
use App\Services\Crm\InboxSummary;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function operator(): User
{
    return User::withRole(RoleName::Operator)->firstOrFail();
}

function inboxItem(array $attributes = []): Interaction
{
    return Interaction::create(array_merge([
        'channel' => SocialPlatform::Instagram->value,
        'type' => InteractionType::Comment->value,
        'direction' => 'inbound',
        'external_id' => 'wf-'.uniqid(),
        'author_handle' => 'warga'.uniqid(),
        'text' => 'komentar uji',
        'occurred_at' => now()->subHours(3),
        'status' => InteractionStatus::New->value,
    ], $attributes));
}

/* -----------------------------------------------------------------
 | Bulk actions — the daily-workflow path
 * ----------------------------------------------------------------- */

it('closes a whole selection in one request', function () {
    $items = collect(range(1, 3))->map(fn () => inboxItem());

    $this->actingAs(operator())
        ->post(route('interactions.bulk'), [
            'action' => 'done',
            'ids' => $items->pluck('id')->all(),
        ])
        ->assertRedirect();

    foreach ($items as $item) {
        $item->refresh();
        expect($item->status)->toBe(InteractionStatus::Done)
            ->and($item->resolved_at)->not->toBeNull();
    }
});

it('does not rewrite who closed something earlier', function () {
    $earlier = now()->subWeek();
    $curator = User::withRole(RoleName::Curator)->firstOrFail();

    $alreadyDone = inboxItem([
        'status' => InteractionStatus::Done->value,
        'resolved_at' => $earlier,
        'resolved_by' => $curator->id,
    ]);
    $fresh = inboxItem();

    $this->actingAs(operator())
        ->post(route('interactions.bulk'), [
            'action' => 'done',
            'ids' => [$alreadyDone->id, $fresh->id],
        ]);

    // The original resolver survives a later bulk tidy-up.
    expect($alreadyDone->refresh()->resolved_by)->toBe($curator->id)
        ->and($alreadyDone->resolved_at->toDateString())->toBe($earlier->toDateString());

    expect($fresh->refresh()->resolved_by)->toBe(operator()->id);
});

it('marks spam as ignored without pretending it was answered', function () {
    $item = inboxItem();

    $this->actingAs(operator())
        ->post(route('interactions.bulk'), ['action' => 'ignore', 'ids' => [$item->id]]);

    $item->refresh();

    expect($item->status)->toBe(InteractionStatus::Ignored)
        ->and($item->first_response_at)->toBeNull();
});

it('queues a selection for re-classification without needing a worker', function () {
    $item = inboxItem(['sentiment' => Sentiment::Positive->value, 'ai_classified_at' => now()]);

    $this->actingAs(operator())
        ->post(route('interactions.bulk'), ['action' => 'reclassify', 'ids' => [$item->id]]);

    // Clearing the stamp is the whole mechanism — the scheduled run picks it up.
    expect($item->refresh()->ai_classified_at)->toBeNull();
});

it('refuses to reassign work for a role that may only handle it', function () {
    $item = inboxItem();

    // A PIC answers messages but does not distribute them.
    $this->actingAs(User::withRole(RoleName::Pic)->firstOrFail())
        ->post(route('interactions.bulk'), [
            'action' => 'assign',
            'ids' => [$item->id],
            'assigned_to' => operator()->id,
        ])
        ->assertForbidden();

    expect($item->refresh()->assigned_to)->toBeNull();
});

it('rejects an empty or oversized selection', function () {
    $this->actingAs(operator())
        ->post(route('interactions.bulk'), ['action' => 'done', 'ids' => []])
        ->assertSessionHasErrors('ids');

    $this->actingAs(operator())
        ->post(route('interactions.bulk'), [
            'action' => 'done',
            'ids' => array_fill(0, 201, (string) Str::uuid()),
        ])
        ->assertSessionHasErrors('ids');
});

/* -----------------------------------------------------------------
 | Manual entry — the fallback for channels with no API
 * ----------------------------------------------------------------- */

it('records a TikTok DM typed in by hand', function () {
    $this->actingAs(operator())
        ->post(route('interactions.storeManual'), [
            'channel' => SocialPlatform::TikTok->value,
            'type' => InteractionType::DirectMessage->value,
            'author_handle' => 'penanya_tiktok',
            'text' => 'kak mau tanya biaya kuliahnya berapa ya',
        ])
        ->assertRedirect();

    $interaction = Interaction::where('author_handle', 'penanya_tiktok')->firstOrFail();

    expect($interaction->channel)->toBe(SocialPlatform::TikTok)
        ->and($interaction->type)->toBe(InteractionType::DirectMessage)
        // Namespaced so it can never collide with a real platform id.
        ->and($interaction->external_id)->toStartWith('manual-')
        // Treated exactly like a synced comment from here on.
        ->and($interaction->contact_id)->not->toBeNull()
        ->and($interaction->ai_classified_at)->toBeNull();
});

it('creates a reachable contact when a WhatsApp number is given', function () {
    $this->actingAs(operator())
        ->post(route('interactions.storeManual'), [
            'channel' => SocialPlatform::WhatsApp->value,
            'type' => InteractionType::DirectMessage->value,
            'phone' => '0813-9999-1234',
            'author_name' => 'Ibu Sari',
            'text' => 'saya mau daftarkan anak saya',
        ])
        ->assertRedirect();

    $contact = Contact::where('phone_e164', '6281399991234')->firstOrFail();

    expect($contact->full_name)->toBe('Ibu Sari');
    expect(ContactIdentity::where('contact_id', $contact->id)
        ->where('channel', SocialPlatform::WhatsApp->value)->exists())->toBeTrue();
});

it('needs either a username or a number', function () {
    $this->actingAs(operator())
        ->post(route('interactions.storeManual'), [
            'channel' => SocialPlatform::TikTok->value,
            'type' => InteractionType::DirectMessage->value,
            'text' => 'halo',
        ])
        ->assertSessionHasErrors(['author_handle', 'phone']);
});

it('refuses manual entry for channels that are synced automatically', function () {
    // Typing in an Instagram comment by hand would duplicate the sync.
    $this->actingAs(operator())
        ->post(route('interactions.storeManual'), [
            'channel' => SocialPlatform::Instagram->value,
            'type' => InteractionType::Comment->value,
            'author_handle' => 'seseorang',
            'text' => 'halo',
        ])
        ->assertSessionHasErrors('channel');
});

/* -----------------------------------------------------------------
 | Shared summary — the dashboard and the inbox must agree
 * ----------------------------------------------------------------- */

it('counts an SLA breach only once it is genuinely late', function () {
    $summary = app(InboxSummary::class);
    $before = $summary->headline()['sla_breach'];

    // Urgent, unanswered, but only just arrived.
    inboxItem(['is_urgent' => true, 'occurred_at' => now()->subMinutes(5)]);
    expect($summary->headline()['sla_breach'])->toBe($before);

    // Urgent, unanswered, well past the target.
    inboxItem([
        'is_urgent' => true,
        'occurred_at' => now()->subHours(config('crm.sla.urgent_hours') + 5),
    ]);
    expect($summary->headline()['sla_breach'])->toBe($before + 1);
});

it('stops counting a breach once someone has responded', function () {
    $summary = app(InboxSummary::class);
    $before = $summary->headline()['sla_breach'];

    $late = inboxItem([
        'is_urgent' => true,
        'occurred_at' => now()->subHours(config('crm.sla.urgent_hours') + 5),
    ]);

    expect($summary->headline()['sla_breach'])->toBe($before + 1);

    $late->forceFill(['first_response_at' => now()])->save();

    expect($summary->headline()['sla_breach'])->toBe($before);
});

it('reports response time as a median so one late reply cannot skew it', function () {
    // Three answered within an hour, one answered a fortnight later.
    foreach ([1, 1, 1, 336] as $hours) {
        inboxItem([
            'occurred_at' => now()->subDays(2),
            'first_response_at' => now()->subDays(2)->addHours($hours),
        ]);
    }

    $sla = app(InboxSummary::class)->slaPerformance();

    expect($sla['answered'])->toBeGreaterThanOrEqual(4)
        // A mean would be dragged past 80 hours by the outlier.
        ->and($sla['median_hours'])->toBeLessThan(24);
});

it('fills quiet days with zeros rather than skipping them', function () {
    $trend = app(InboxSummary::class)->sentimentTrend(7);

    expect($trend['labels'])->toHaveCount(7)
        ->and($trend['positive'])->toHaveCount(7)
        ->and($trend['negative'])->toHaveCount(7);
});
