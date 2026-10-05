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

it('has exactly the five permissions it needs, and no more', function () {
    $user = followUpUser();

    $granted = [
        Permission::ViewDashboard,
        Permission::ViewTickets,
        Permission::HandleTickets,
        // Whoever finishes the follow-up closes the ticket.
        Permission::CloseTickets,
        // Daftar Mahasiswa, read-only and limited to their own students.
        Permission::ViewStudents,
    ];

    foreach ($granted as $permission) {
        expect($user->hasPermission($permission))->toBeTrue();
    }

    // Everything else. Enumerated rather than counted, so a permission added to
    // the enum later cannot quietly widen this role.
    foreach (Permission::cases() as $permission) {
        if (in_array($permission, $granted, true)) {
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
        'action' => 'ditelepon',
        'response_text' => 'Sudah dihubungi lewat telepon.',
        'status' => 'on_proses',
    ])->assertRedirect();

    expect($ticket->fresh()->followUps()->count())->toBe(1);
    expect($ticket->fresh()->status)->toBe(TicketStatus::FollowUp);
});

it('closes its own ticket once the follow-up is done, with a resolution note', function () {
    $user = followUpUser();
    $ticket = ticketFor($user);

    // The close form is on its ticket page.
    $this->actingAs($user)->get(route('tickets.show', $ticket))
        ->assertOk()
        ->assertSee(route('tickets.close', $ticket), false);

    // A resolution is required, as for every role.
    $this->actingAs($user)
        ->post(route('tickets.close', $ticket), ['resolution_note' => ''])
        ->assertSessionHasErrors('resolution_note');

    $this->actingAs($user)
        ->post(route('tickets.close', $ticket), ['resolution_note' => 'Mahasiswa sudah registrasi ulang.'])
        ->assertRedirect();

    $ticket->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed)
        ->and($ticket->closed_by)->toBe($user->id)
        ->and($ticket->resolution_note)->toBe('Mahasiswa sudah registrasi ulang.');
});

it('cannot close someone else\'s ticket, nor close through the status or follow-up forms', function () {
    $user = followUpUser();

    // Someone else's ticket: refused before anything happens.
    $theirs = ticketFor(User::withRole(RoleName::Pic)->firstOrFail());
    $this->actingAs($user)
        ->post(route('tickets.close', $theirs), ['resolution_note' => 'Bukan tiket saya.'])
        ->assertForbidden();
    expect($theirs->fresh()->status)->not->toBe(TicketStatus::Closed);

    $ticket = ticketFor($user);

    // The status route is EditTickets, which this role does not have.
    $this->actingAs($user)
        ->post(route('tickets.status', $ticket), ['status' => TicketStatus::Closed->value])
        ->assertForbidden();

    // And the follow-up form's own vocabulary cannot reach a closed state:
    // closing goes through the close form, which demands a resolution.
    $this->actingAs($user)->post(route('tickets.followUp', $ticket), [
        'action' => 'ditelepon',
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
    // business in, so a future permission slip shows up here. (/students is
    // no longer one of them: see the next test.)
    foreach (['/students/unsigned', '/students/import', '/interactions', '/contacts', '/monitoring', '/reports/tickets', '/users'] as $uri) {
        $this->actingAs($user)->get($uri)->assertForbidden();
    }
});

it('sees its own students in Daftar Mahasiswa, read-only', function () {
    $user = followUpUser();
    $other = User::withRole(RoleName::Pic)->firstOrFail();

    $mine = \App\Models\Student::create(['nim' => 'FUSTU00001', 'nama' => 'Mahasiswa Milik FU', 'assigned_to' => $user->id, 'assignment_status' => 'assigned']);
    $theirs = \App\Models\Student::create(['nim' => 'FUSTU00002', 'nama' => 'Mahasiswa Orang Lain', 'assigned_to' => $other->id, 'assignment_status' => 'assigned']);

    $this->actingAs($user)->get(route('students.index', ['q' => 'FUSTU']))
        ->assertOk()
        ->assertSee('Mahasiswa Milik FU')
        ->assertDontSee('Mahasiswa Orang Lain');

    $this->actingAs($user)->get(route('students.show', $mine))->assertOk();
    $this->actingAs($user)->get(route('students.show', $theirs))->assertForbidden();

    // Looking, not editing.
    $this->actingAs($user)->put(route('students.update', $mine), ['catatan' => 'diubah'])->assertForbidden();

    // The sidebar now offers the list.
    $this->actingAs($user)->get(route('dashboard'))->assertSee(route('students.index'), false);
});

it('sees the ticket information read-only, without the status, flag or assignment forms', function () {
    $user = followUpUser();
    $ticket = ticketFor($user);

    $html = $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk()->getContent();

    expect($html)
        ->toContain('Hanya lihat')
        ->toContain('Tambah Follow Up')
        ->not->toContain(route('tickets.status', $ticket))
        ->not->toContain(route('tickets.flag', $ticket))
        ->not->toContain(route('tickets.assign', $ticket))
        ->and(preg_match('/id="subject"[^>]*disabled/', $html))->toBe(1);
});

it('is refused when it edits the ticket, its status or its flag directly', function () {
    $user = followUpUser();
    $ticket = ticketFor($user);

    $this->actingAs($user)->put(route('tickets.update', $ticket), [
        'subject' => 'Diubah follow up', 'priority' => 'normal',
    ])->assertForbidden();
    $this->actingAs($user)->post(route('tickets.status', $ticket), ['status' => 'on_proses'])->assertForbidden();
    $this->actingAs($user)->post(route('tickets.flag', $ticket), ['flag' => \App\Enums\TicketFlag::cases()[0]->value])->assertForbidden();

    expect($ticket->refresh()->subject)->toBe('Tiket uji follow up');
});

it('leaves editing to the roles that handle tickets in full', function () {
    foreach ([RoleName::Manager, RoleName::Pic, RoleName::Operator] as $role) {
        expect(User::withRole($role)->firstOrFail()->hasPermission(Permission::EditTickets))
            ->toBeTrue("{$role->value} harus tetap bisa mengubah tiket");
    }
});
