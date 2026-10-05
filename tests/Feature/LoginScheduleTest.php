<?php

/**
 * Jadwal login per pengguna: server-side, in WIB, off unless switched on.
 */

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;

uses(DatabaseTransactions::class);

function scheduledUser(array $schedule = []): User
{
    $user = new User;
    $user->forceFill([
        'name' => 'Uji Jadwal',
        'email' => 'uji-jadwal-'.uniqid().'@example.test',
        'password' => 'Rahasia-Uji-123',
        'role_id' => User::withRole(RoleName::Pic)->firstOrFail()->role_id,
        'is_active' => true,
    ] + $schedule)->save();

    return $user->refresh();
}

function wib(string $at): Carbon
{
    return Carbon::parse($at, 'Asia/Jakarta');
}

it('allows everything when no schedule is enabled', function () {
    $user = scheduledUser(['login_start_time' => '08:00', 'login_end_time' => '17:00']);

    expect($user->loginAllowedAt(wib('2026-10-05 23:30')))->toBeTrue();
});

it('holds to the dates and the hours, in WIB', function () {
    $user = scheduledUser([
        'login_schedule_enabled' => true,
        'login_start_date' => '2026-10-05',
        'login_end_date' => '2026-10-10',
        'login_start_time' => '08:00',
        'login_end_time' => '17:00',
    ]);

    expect($user->loginAllowedAt(wib('2026-10-05 08:00')))->toBeTrue()
        ->and($user->loginAllowedAt(wib('2026-10-10 17:00')))->toBeTrue()
        ->and($user->loginAllowedAt(wib('2026-10-07 07:59')))->toBeFalse()
        ->and($user->loginAllowedAt(wib('2026-10-07 17:01')))->toBeFalse()
        ->and($user->loginAllowedAt(wib('2026-10-04 12:00')))->toBeFalse()
        ->and($user->loginAllowedAt(wib('2026-10-11 12:00')))->toBeFalse()
        // 10:00 UTC is 17:00 WIB: inside. 10:30 UTC is 17:30 WIB: outside.
        ->and($user->loginAllowedAt(Carbon::parse('2026-10-07 10:00', 'UTC')))->toBeTrue()
        ->and($user->loginAllowedAt(Carbon::parse('2026-10-07 10:30', 'UTC')))->toBeFalse();
});

it('reads a window like 22:00–06:00 as running past midnight', function () {
    $user = scheduledUser(['login_schedule_enabled' => true, 'login_start_time' => '22:00', 'login_end_time' => '06:00']);

    expect($user->loginAllowedAt(wib('2026-10-05 23:00')))->toBeTrue()
        ->and($user->loginAllowedAt(wib('2026-10-06 05:00')))->toBeTrue()
        ->and($user->loginAllowedAt(wib('2026-10-06 12:00')))->toBeFalse();
});

it('refuses the login outside the schedule with a clear message', function () {
    $user = scheduledUser(['login_schedule_enabled' => true, 'login_start_date' => '2030-01-01']);

    $this->post('http://192.168.1.10:5566/login', ['email' => $user->email, 'password' => 'Rahasia-Uji-123'])
        ->assertSessionHasErrors(['email' => 'Akses login Anda berada di luar jadwal yang telah ditentukan.']);

    $this->assertGuest();
});

it('keeps the generic message for a wrong password, schedule or not', function () {
    $user = scheduledUser(['login_schedule_enabled' => true, 'login_start_date' => '2030-01-01']);

    $this->post('http://192.168.1.10:5566/login', ['email' => $user->email, 'password' => 'salah-salah-1'])
        ->assertSessionHasErrors(['email' => 'Email atau kata sandi salah, atau akun Anda dinonaktifkan.']);
});

it('signs out an open session once the schedule no longer allows it', function () {
    $user = scheduledUser();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['login_schedule_enabled' => true, 'login_end_date' => '2020-01-01'])->save();

    $this->actingAs($user->refresh())->get(route('dashboard'))->assertRedirect(route('login'));
});

it('lets only a Super Admin set the schedule, from the user form', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    $user = scheduledUser();

    $payload = [
        'name' => $user->name,
        'email' => $user->email,
        'role_id' => $user->role_id,
        'is_active' => '1',
        'login_schedule_enabled' => '1',
        'login_start_date' => '2026-10-05',
        'login_end_date' => '2026-10-10',
        'login_start_time' => '08:00',
        'login_end_time' => '17:00',
    ];

    $this->actingAs($admin)->put(route('users.update', $user), $payload)->assertRedirect(route('users.index'));

    $user->refresh();

    expect($user->login_schedule_enabled)->toBeTrue()
        ->and($user->login_start_date->toDateString())->toBe('2026-10-05')
        ->and(substr($user->login_end_time, 0, 5))->toBe('17:00')
        ->and($user->loginScheduleLabel())->toBe('05-10-2026 s/d 10-10-2026 · 08:00–17:00 WIB');

    // Switched off again: normal login.
    $this->actingAs($admin)->put(route('users.update', $user), ['login_schedule_enabled' => '0'] + $payload);

    expect($user->refresh()->login_schedule_enabled)->toBeFalse()
        ->and($user->loginAllowedAt(wib('2031-01-01 03:00')))->toBeTrue();
});

it('rejects an enabled schedule with nothing in it, or dates the wrong way round', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    $user = scheduledUser();
    $base = ['name' => $user->name, 'email' => $user->email, 'role_id' => $user->role_id, 'is_active' => '1'];

    $this->actingAs($admin)->put(route('users.update', $user), $base + ['login_schedule_enabled' => '1'])
        ->assertSessionHasErrors('login_schedule_enabled');

    $this->actingAs($admin)->put(route('users.update', $user), $base + [
        'login_schedule_enabled' => '1', 'login_start_date' => '2026-10-10', 'login_end_date' => '2026-10-05',
    ])->assertSessionHasErrors('login_end_date');
});

it('never lets a Super Admin put a schedule on their own account', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $this->actingAs($admin)->put(route('users.update', $admin), [
        'name' => $admin->name, 'email' => $admin->email, 'role_id' => $admin->role_id, 'is_active' => '1',
        'login_schedule_enabled' => '1', 'login_end_date' => '2020-01-01',
    ]);

    expect($admin->refresh()->login_schedule_enabled)->toBeFalse();
});
