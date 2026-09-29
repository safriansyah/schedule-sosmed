<?php

/**
 * Pasting a bcrypt hash from an outside generator instead of typing a password.
 *
 * The trap this guards: password_verify() accepts $2a$, $2b$ and $2y$, but
 * PHP 8.2's password_get_info() recognises only $2y$ — so Laravel's `hashed`
 * cast treated the first two as plain text and hashed them a SECOND time. The
 * account then had a password nobody knew, and nothing said so.
 */

use App\Models\Role;
use App\Models\User;
use App\Support\PasswordInput;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

uses(DatabaseTransactions::class);

function outsideHash(string $plain, string $prefix = '$2a$', int $cost = 10): string
{
    // What a generator on the web hands back: usually $2a$, usually cost 10,
    // not the $2y$/cost-12 this app produces itself.
    return $prefix.substr(password_hash($plain, PASSWORD_BCRYPT, ['cost' => $cost]), 4);
}

it('stores every bcrypt prefix without hashing it again', function () {
    $plain = 'RahasiaKuat123';

    foreach (['$2y$', '$2a$', '$2b$'] as $prefix) {
        $user = new User;
        $user->password = outsideHash($plain, $prefix);

        $stored = $user->getAttributes()['password'];

        expect(strlen($stored))->toBe(60)
            // Normalised, so PHP's own tooling recognises it.
            ->and($stored)->toStartWith('$2y$')
            // And the point of the whole exercise: the original password works.
            ->and(Hash::check($plain, $stored))->toBeTrue();
    }
});

it('still hashes an ordinary typed password', function () {
    $user = new User;
    $user->password = 'KataSandiBiasa9';

    $stored = $user->getAttributes()['password'];

    expect($stored)->not->toBe('KataSandiBiasa9')
        ->and(Hash::check('KataSandiBiasa9', $stored))->toBeTrue();
});

it('lets someone log in with a password whose hash was pasted in the form', function () {
    $plain = 'SandiDitempel7';
    $hash = outsideHash($plain);
    $email = 'tempel'.random_int(10000, 99999).'@test.local';

    $this->actingAs(admin())
        ->post(route('users.store'), [
            'name' => 'Pengguna Tempel',
            'email' => $email,
            'role_id' => Role::where('name', 'operator')->value('id'),
            'password' => $hash,
            'password_confirmation' => $hash,
            'is_active' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // The real proof: the login screen accepts the ORIGINAL password.
    auth()->logout();

    $this->post('/login', ['email' => $email, 'password' => $plain])
        ->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue()
        ->and(auth()->user()->email)->toBe($email);
});

it('refuses a hash that was only half pasted', function () {
    $whole = outsideHash('Apapun123');
    $half = substr($whole, 0, 30);

    // Long enough, has letters and numbers — it passes every strength rule.
    // Stored as a literal password it would lock the account for good.
    $this->actingAs(admin())
        ->from(route('users.create'))
        ->post(route('users.store'), [
            'name' => 'Pengguna Potong',
            'email' => 'potong'.random_int(10000, 99999).'@test.local',
            'role_id' => Role::where('name', 'operator')->value('id'),
            'password' => $half,
            'password_confirmation' => $half,
        ])
        ->assertSessionHasErrors('password');

    expect(PasswordInput::looksLikeBrokenHash($half))->toBeTrue()
        ->and(PasswordInput::looksLikeBrokenHash('KataSandiBiasa9'))->toBeFalse();
});

it('changes an existing password to a pasted hash', function () {
    $plain = 'SandiBaru2026';
    $user = operatorNamed('tempel-edit@test.local');

    $this->actingAs(admin())
        ->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'password' => outsideHash($plain, '$2b$', 12),
            'password_confirmation' => outsideHash($plain, '$2b$', 12),
            'is_active' => 1,
        ])
        ->assertSessionHasErrors('password');   // two hashes of the same
                                                // password differ: the salt is
                                                // random, so `confirmed` fails.

    // Which is why the hint tells people to paste the SAME string twice.
    $hash = outsideHash($plain, '$2b$', 12);

    $this->actingAs(admin())
        ->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'password' => $hash,
            'password_confirmation' => $hash,
            'is_active' => 1,
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check($plain, $user->refresh()->password))->toBeTrue();
});
