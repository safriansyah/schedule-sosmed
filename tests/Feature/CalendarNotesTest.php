<?php
use App\Enums\{Permission, RoleName};
use App\Models\{CalendarEvent, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('lets admin and director add calendar notes', function () {
    foreach ([RoleName::SuperAdmin, RoleName::Director] as $role) {
        $user = User::withRole($role)->firstOrFail();

        expect($user->hasPermission(Permission::ManageCalendarNotes))->toBeTrue();

        $this->actingAs($user)->postJson(route('calendar.notes.store'), [
            'title' => "Catatan {$role->value}",
            'type' => 'deadline',
            'note' => 'Deadline konten bulan depan',
            'starts_at' => now()->addWeek()->toDateString(),
        ])->assertCreated()->assertJsonPath('message', 'Catatan ditambahkan.');
    }

    expect(CalendarEvent::count())->toBeGreaterThanOrEqual(2);
});

it('lets curator and verifier add notes too', function () {
    // Calendar notes are open to every role now.
    foreach ([RoleName::Curator, RoleName::Verifier] as $role) {
        $user = User::withRole($role)->firstOrFail();

        expect($user->hasPermission(Permission::ManageCalendarNotes))->toBeTrue();

        $this->actingAs($user)
            ->postJson(route('calendar.notes.store'), [
                'title' => "Catatan {$role->value}", 'type' => 'note',
                'starts_at' => now()->toDateString(),
            ])->assertCreated();
    }
});

it('shows notes in the calendar feed alongside content', function () {
    $director = User::withRole(RoleName::Director)->firstOrFail();

    $this->actingAs($director)->postJson(route('calendar.notes.store'), [
        'title' => 'Rapat Redaksi', 'type' => 'reminder',
        'starts_at' => now()->addDays(3)->toDateString(),
    ])->assertCreated();

    // Every role WITH calendar access can see the note.
    // Roles that hold the permission, not every role there is. "Operator Follow
// Up" deliberately does without the read baseline the other roles share — its
// whole job is following up its own tickets — so iterating RoleName::cases()
// here would assert that a restricted role is not restricted.
    $roles = array_filter(
        RoleName::cases(),
        fn (RoleName $r) => User::withRole($r)->firstOrFail()->hasPermission(Permission::ViewCalendar),
    );

    foreach ($roles as $role) {
        $this->actingAs(User::withRole($role)->firstOrFail())
            ->getJson(route('calendar.events', [
                'start' => now()->subWeek()->toDateString(),
                'end' => now()->addWeeks(2)->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Rapat Redaksi']);
    }
});

it('only lets the author edit or delete their own note', function () {
    $director = User::withRole(RoleName::Director)->firstOrFail();
    $creative = User::withRole(RoleName::Creative)->firstOrFail();

    $note = CalendarEvent::create([
        'user_id' => $director->id, 'title' => 'Milik Direktur',
        'type' => 'note', 'starts_at' => now()->addDay(),
    ]);

    // Another note-capable user cannot touch it…
    $this->actingAs($creative)->putJson(route('calendar.notes.update', $note), [
        'title' => 'Dibajak', 'type' => 'note', 'starts_at' => now()->addDay()->toDateString(),
    ])->assertForbidden();

    $this->actingAs($creative)->deleteJson(route('calendar.notes.destroy', $note))->assertForbidden();

    // …but the author can.
    $this->actingAs($director)->putJson(route('calendar.notes.update', $note), [
        'title' => 'Judul Baru', 'type' => 'deadline', 'starts_at' => now()->addDay()->toDateString(),
    ])->assertOk();

    expect($note->fresh()->title)->toBe('Judul Baru');

    // Super admin may override.
    $this->actingAs(User::withRole(RoleName::SuperAdmin)->firstOrFail())
        ->deleteJson(route('calendar.notes.destroy', $note))->assertOk();
});

it('validates the note payload', function () {
    $this->actingAs(User::withRole(RoleName::Director)->firstOrFail())
        ->postJson(route('calendar.notes.store'), ['type' => 'note'])
        ->assertJsonValidationErrors(['title', 'starts_at']);
});
