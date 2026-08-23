<?php
use App\Enums\{Permission, RoleName, SocialPlatform};
use App\Models\{SocialAccount, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

uses(DatabaseTransactions::class);

it('refuses login for a deactivated account', function () {
    $user = User::withRole(RoleName::Creative)->firstOrFail();
    $user->forceFill(['is_active' => false, 'password' => Hash::make('secret123')])->save();

    $this->post('/login', ['email' => $user->email, 'password' => 'secret123'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('cuts off a session as soon as the user is deactivated', function () {
    $user = User::withRole(RoleName::Creative)->firstOrFail();
    $user->forceFill(['is_active' => true])->save();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('ignores role_id and is_active coming from request data', function () {
    $user = User::withRole(RoleName::Creative)->firstOrFail();
    $superAdminRoleId = \App\Models\Role::where('name', RoleName::SuperAdmin->value)->value('id');
    $original = $user->role_id;

    // Simulates a future profile-update endpoint doing $user->update($request->all())
    $user->update(['name' => 'Nama Baru', 'role_id' => $superAdminRoleId, 'is_active' => false]);

    expect($user->fresh()->role_id)->toBe($original)
        ->and($user->fresh()->is_active)->toBeTrue()
        ->and($user->fresh()->name)->toBe('Nama Baru');
});

it('never exposes the stored access token', function () {
    $account = SocialAccount::create([
        'platform' => SocialPlatform::Instagram, 'name' => 'Rahasia',
        'external_id' => 'sec-'.uniqid(), 'access_token' => 'IGAA-super-secret', 'is_active' => true,
    ]);

    // Hidden from serialisation…
    expect($account->toArray())->not->toHaveKey('access_token')
        ->and(json_encode($account))->not->toContain('IGAA-super-secret');

    // …and encrypted at rest.
    $raw = \DB::table('social_accounts')->where('id', $account->id)->value('access_token');
    expect($raw)->not->toContain('IGAA-super-secret');

    // Not rendered on the management page either.
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    $this->actingAs($admin)->get(route('accounts.edit', $account))
        ->assertOk()
        ->assertDontSee('IGAA-super-secret');
});

it('lets only permitted roles manage social accounts', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $director = User::withRole(RoleName::Director)->firstOrFail();
    $admin    = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $this->actingAs($admin)->get(route('accounts.index'))->assertOk();
    $this->actingAs($director)->get(route('accounts.index'))->assertOk();   // read-only view
    $this->actingAs($director)->get(route('accounts.create'))->assertForbidden();
    $this->actingAs($creative)->get(route('accounts.index'))->assertForbidden();
});
