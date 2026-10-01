<?php
use App\Enums\{ContentStatus, Permission, RoleName};
use App\Models\{Content, Role, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('lets super admin manage users end to end', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    $creativeRole = Role::where('name', RoleName::Creative->value)->firstOrFail();

    $this->actingAs($admin)->get(route('users.index'))->assertOk()->assertSee('Pengguna');
    $this->actingAs($admin)->get(route('users.create'))->assertOk()->assertSee('Hak Akses');

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Staf Baru', 'email' => 'staf.baru@example.com',
        'role_id' => $creativeRole->id, 'password' => 'rahasia123',
        'password_confirmation' => 'rahasia123', 'is_active' => '1',
    ])->assertRedirect(route('users.index'));

    $user = User::where('email', 'staf.baru@example.com')->firstOrFail();
    expect($user->role_id)->toBe($creativeRole->id)->and($user->is_active)->toBeTrue();

    // The new account can actually sign in.
    $this->post('/login', ['email' => 'staf.baru@example.com', 'password' => 'rahasia123'])
        ->assertRedirect(route('dashboard'));
});

it('stops non-admins from reaching user management', function () {
    foreach ([RoleName::Creative, RoleName::Curator, RoleName::Verifier] as $role) {
        $this->actingAs(User::withRole($role)->firstOrFail())
            ->get(route('users.index'))->assertForbidden();
    }

    // Director may look but not change.
    $this->actingAs(User::withRole(RoleName::Director)->firstOrFail())
        ->get(route('users.index'))->assertOk();
    $this->actingAs(User::withRole(RoleName::Director)->firstOrFail())
        ->get(route('users.create'))->assertForbidden();
});

it('prevents an admin from deleting or deactivating themselves', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $this->actingAs($admin)->delete(route('users.destroy', $admin))->assertRedirect();
    expect($admin->fresh())->not->toBeNull();

    $this->actingAs($admin)->put(route('users.update', $admin), [
        'name' => $admin->name, 'email' => $admin->email,
        'role_id' => $admin->role_id, 'is_active' => '0',
    ])->assertRedirect();

    expect($admin->fresh()->is_active)->toBeTrue();
});

it('shows the calendar to every role that may see it', function () {
    // Roles that hold the permission, not every role there is. "Operator Follow
// Up" deliberately does without the read baseline the other roles share — its
// whole job is following up its own tickets — so iterating RoleName::cases()
// here would assert that a restricted role is not restricted.
    $roles = array_filter(
        RoleName::cases(),
        fn (RoleName $r) => User::withRole($r)->firstOrFail()->hasPermission(Permission::ViewCalendar),
    );

    expect($roles)->not->toBeEmpty();

    foreach ($roles as $role) {
        $this->actingAs(User::withRole($role)->firstOrFail())
            ->get(route('calendar.index'))->assertOk()->assertSee('Kalender');
    }
});

it('feeds scheduled content into the calendar', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();

    $content = Content::create([
        'title' => 'Agenda Uji', 'status' => ContentStatus::Scheduled,
        'created_by' => $creative->id, 'scheduled_at' => now()->addDays(2),
    ]);

    $this->actingAs($creative)
        ->getJson(route('calendar.events', [
            'start' => now()->subWeek()->toDateString(),
            'end' => now()->addWeeks(2)->toDateString(),
        ]))
        ->assertOk()
        ->assertJsonFragment(['id' => $content->id, 'title' => 'Agenda Uji']);
});

it('lets settings be opened and the password changed by anyone', function () {
    $user = User::withRole(RoleName::Verifier)->firstOrFail();
    $user->forceFill(['password' => bcrypt('lama12345')])->save();

    $this->actingAs($user)->get(route('settings.edit'))->assertOk()->assertSee('Ganti Kata Sandi');

    $this->actingAs($user)->put(route('settings.password'), [
        'current_password' => 'lama12345',
        'password' => 'baru123456', 'password_confirmation' => 'baru123456',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('baru123456', $user->fresh()->password))->toBeTrue();
});
