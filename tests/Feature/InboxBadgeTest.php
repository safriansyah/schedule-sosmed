<?php

/**
 * The sidebar badge has to mean the same thing as the tab it points at.
 *
 * It did not. `SUM(needs_reply = 1) as needs_reply` aliased an aggregate to the
 * name of a real column, so Eloquent applied that column's boolean cast to it:
 * the sum came back as `true`, and `(int) true` is 1. The sidebar advertised
 * "1" beside a tab holding 27 rows, and nothing anywhere errored. The other
 * three aliases were not column names, so only that one badge lied — which is
 * precisely why it read as a counting mistake rather than a casting one.
 *
 * The badge also has to apply the same filters as the tab, or it counts work
 * the tab will not show.
 */

use App\Enums\{Intent, InteractionStatus, RoleName};
use App\Models\{Interaction, User};
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;

uses(DatabaseTransactions::class);

/** The private badge builder, called the way the view composer calls it. */
function navBadges(): array
{
    $method = new ReflectionMethod(AppServiceProvider::class, 'interactionBadges');
    $method->setAccessible(true);

    return $method->invoke(app()->getProvider(AppServiceProvider::class));
}

it('counts the same interactions the tab shows', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    Auth::login($admin);

    $open = Interaction::inbound()->open()->withoutTicket();

    $expected = [
        'interactions.indexurgent' => (clone $open)->urgent()->count(),
        'interactions.indexquestion' => (clone $open)->where('intent', Intent::Question->value)->count(),
        'interactions.indexneeds_reply' => (clone $open)->where('needs_reply', true)->count(),
        'interactions.indexmine' => (clone $open)->where('assigned_to', $admin->id)->count(),
    ];

    $badges = navBadges();

    foreach ($expected as $key => $count) {
        expect($badges[$key])->toBe($count, "badge {$key} tidak sama dengan isi tabnya");
    }
});

it('returns a real total, not a cast boolean', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    Auth::login($admin);

    // Three rows that all need a reply. A badge that has been squashed to a
    // boolean reports 1 here; a working one reports at least 3.
    foreach (range(1, 3) as $i) {
        Interaction::create([
            'channel' => 'instagram',
            'external_id' => 'badge-uji-'.uniqid(),
            'type' => 'comment',
            'direction' => 'inbound',
            'status' => InteractionStatus::New,
            'needs_reply' => true,
            'text' => 'Mohon dibalas '.$i,
        ]);
    }

    expect(navBadges()['interactions.indexneeds_reply'])->toBeGreaterThanOrEqual(3);
});

it('leaves out interactions that already became tickets', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    Auth::login($admin);

    $before = navBadges()['interactions.indexneeds_reply'];

    $interaction = Interaction::create([
        'channel' => 'instagram',
        'external_id' => 'badge-tiket-'.uniqid(),
        'type' => 'comment',
        'direction' => 'inbound',
        'status' => InteractionStatus::New,
        'needs_reply' => true,
        'text' => 'Ini akan jadi tiket',
    ]);

    expect(navBadges()['interactions.indexneeds_reply'])->toBe($before + 1);

    // Once it is a ticket it is worked in Ticketing, and the inbox tab drops
    // it — so the badge must drop it too.
    app(App\Services\Tickets\TicketService::class)->createFromInteraction($interaction, $admin);

    expect(navBadges()['interactions.indexneeds_reply'])->toBe($before);
});

it('names the open queue in the heading', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    // Arriving from a sidebar badge, the page has to say which queue it is —
    // the number alone does not tell you what you are looking at.
    $expected = [
        'urgent' => 'Negatif Urgent',
        'needs_reply' => 'Perlu Dibalas',
        'question' => 'Pertanyaan',
        // The heading follows the TAB's own label, not the sidebar link's —
        // the sidebar says "Semua Interaksi", the tab is called "Semua".
        'all' => 'Semua',
        'done' => 'Selesai',
    ];

    foreach ($expected as $tab => $label) {
        $this->actingAs($admin)
            ->get('/interactions?tab='.$tab)
            ->assertOk()
            ->assertSee('('.$label.')', false);
    }
});
