<?php

/**
 * "Operator Follow Up" — the narrowest role in the system.
 *
 * Its job is one verb: follow up the tickets handed to them. Everything else is
 * withheld, including the read baseline every other role gets, so the tests
 * that matter are the negative ones — a role defined by what it cannot do is
 * only as good as the walls around it.
 *
 * The walls are query scopes and policy checks, not hidden buttons, so these
 * exercise the routes directly rather than looking at the markup.
 */

use App\Enums\{Permission, RoleName, TicketSource, TicketStatus};
use App\Models\{Ticket, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function followUpUser(): User
{
    return User::withRole(RoleName::FollowUp)->firstOrFail();
}

function ticketFor(?User $assignee): Ticket
{
    return Ticket::create([
        'number' => 'TKT-UJI-'.substr(uniqid(), -6),
        'subject' => 'Tiket uji follow up',
        'description' => 'Untuk menguji batas role.',
        'source' => TicketSource::Manual,
        'status' => TicketStatus::Assigned,
        'assigned_to' => $assignee?->id,
        'created_by' => User::withRole(RoleName::Manager)->firstOrFail()->id,
    ]);
}

it('has exactly the three permissions it needs, and no more', function () {
    $user = followUpUser();

    // The job.
    expect($user->hasPermission(Permission::ViewDashboard))->toBeTrue();
    expect($user->hasPermission(Permission::ViewTickets))->toBeTrue();
    expect($user->hasPermission(Permission::HandleTickets))->toBeTrue();

    // Everything else. Enumerated rather than counted, so a permission added to
    // the enum later cannot quietly widen this role.
    foreach (Permission::cases() as $permission) {
        if (in_array($permission, [
            Permission::ViewDashboard,
            Permission::ViewTickets,
            Permission::HandleTickets,
        ], true)) {
            continue;
        }

        expect($user->hasPermission($permission))
            ->toBeFalse("role follow up tidak boleh punya {$permission->value}");
    }
});

it('can open the dashboard and the ticket list', function () {
    $user = followUpUser();

    $this->actingAs($user)->get('/dashboard')->assertOk();
    $this->actingAs($user)->get('/tickets')->assertOk();
});

it('sees only the tickets assigned to it', function () {
    $user = followUpUser();
    $other = User::withRole(RoleName::Pic)->firstOrFail();

    $mine = ticketFor($user);
    $theirs = ticketFor($other);

    $html = $this->actingAs($user)->get('/tickets')->assertOk()->getContent();

    expect($html)->toContain($mine->number);
    expect($html)->not->toContain($theirs->number);
});

it('is refused a ticket belonging to someone else', function () {
    $theirs = ticketFor(User::withRole(RoleName::Pic)->firstOrFail());

    $this->actingAs(followUpUser())->get('/tickets/'.$theirs->id)->assertForbidden();
});

it('can record a follow up on its own ticket', function () {
    $user = followUpUser();
    $ticket = ticketFor($user);

    $this->actingAs($user)->post(route('tickets.followUp', $ticket), [
        'action' => 'call',
        'response_text' => 'Sudah dihubungi lewat telepon.',
        'status' => 'on_proses',
    ])->assertRedirect();

    expect($ticket->fresh()->followUps()->count())->toBe(1);
    expect($ticket->fresh()->status)->toBe(TicketStatus::FollowUp);
});

it('cannot close a ticket, even through the follow up form', function () {
    $user = followUpUser();
    $ticket = ticketFor($user);

    // The status route is the honest way to close, and it is gated.
    $this->actingAs($user)
        ->post(route('tickets.status', $ticket), ['status' => TicketStatus::Closed->value])
        ->assertForbidden();

    // And the follow-up form's own vocabulary cannot reach a closed state:
    // anything unrecognised falls back to "on proses", never to closed.
    $this->actingAs($user)->post(route('tickets.followUp', $ticket), [
        'action' => 'call',
        'status' => TicketStatus::Closed->value,
    ])->assertRedirect();

    expect($ticket->fresh()->status)->not->toBe(TicketStatus::Closed);
});

it('cannot create or reassign tickets', function () {
    $user = followUpUser();
    $ticket = ticketFor($user);

    $this->actingAs($user)->get('/tickets/create')->assertForbidden();

    $this->actingAs($user)
        ->post(route('tickets.assign', $ticket), [
            'assigned_to' => User::withRole(RoleName::Pic)->firstOrFail()->id,
        ])
        ->assertForbidden();
});

it('is kept out of the rest of the app', function () {
    $user = followUpUser();

    // Not a list of every route — a sample across the modules this role has no
    // business in, so a future permission slip shows up here.
    foreach (['/students', '/interactions', '/contacts', '/monitoring', '/reports/tickets', '/users'] as $uri) {
        $this->actingAs($user)->get($uri)->assertForbidden();
    }
});
