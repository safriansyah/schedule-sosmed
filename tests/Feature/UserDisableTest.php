<?php

/**
 * Status pengguna "Disable": the account stays, but it cannot log in, and a
 * session already open is ended on the next request.
 */

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function disableTarget(): User
{
    $user = new User;
    $user->forceFill([
        'name' => 'Uji Disable',
        'email' => 'uji-disable-'.uniqid().'@example.test',
        'password' => 'Rahasia-Uji-123',
        'role_id' => User::withRole(RoleName::Pic)->firstOrFail()->role_id,
        'is_active' => true,
    ])->save();

    return $user;
}

it('disables and re-enables an account in one click', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    $user = disableTarget();

    $this->actingAs($admin)->post(route('users.toggle-active', $user))->assertRedirect();
    expect($user->refresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->post(route('users.toggle-active', $user))->assertRedirect();
    expect($user->refresh()->is_active)->toBeTrue();
});

it('refuses a disabled account at login even with the right password', function () {
    $user = disableTarget();
    $user->forceFill(['is_active' => false])->save();

    $this->post('http://192.168.1.10:5566/login', ['email' => $user->email, 'password' => 'Rahasia-Uji-123'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('signs out a disabled account that is already logged in', function () {
    $user = disableTarget();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user->refresh())->get(route('dashboard'))->assertRedirect(route('login'));
});

it('does not let an admin disable their own account', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $this->actingAs($admin)->post(route('users.toggle-active', $admin))->assertRedirect();

    expect($admin->refresh()->is_active)->toBeTrue();
});

it('saves Disable from the user form', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    $user = disableTarget();

    $this->actingAs($admin)->put(route('users.update', $user), [
        'name' => $user->name,
        'email' => $user->email,
        'role_id' => $user->role_id,
        'is_active' => '0',
    ])->assertRedirect(route('users.index'));

    expect($user->refresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->get(route('users.index', ['q' => 'Uji Disable']))
        ->assertOk()
        ->assertSee('Disable');
});
