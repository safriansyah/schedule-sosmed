<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * The super-admin seeded by DatabaseSeeder. Shared here rather than redeclared
 * per test file — two files defining it is a fatal redeclare once both run.
 */
function admin(): \App\Models\User
{
    return \App\Models\User::withRole(\App\Enums\RoleName::SuperAdmin)->firstOrFail();
}

/**
 * An operator account, created once per email and reused.
 *
 * `role_id` and `is_active` are deliberately NOT fillable on User — they decide
 * privilege, so they are assigned explicitly here rather than through mass
 * assignment, the same way DatabaseSeeder does it. Filling them through
 * create() silently leaves the user role-less, which shows up as a redirect
 * rather than a permission error and is thoroughly confusing to debug.
 */
function operatorNamed(string $email, \App\Enums\RoleName $role = \App\Enums\RoleName::Operator): \App\Models\User
{
    $user = \App\Models\User::firstOrNew(['email' => $email]);

    $user->fill([
        'name' => 'Operator '.str($email)->before('@')->title(),
        'password' => 'password',
    ]);
    $user->role_id = \App\Models\Role::where('name', $role->value)->value('id');
    $user->is_active = true;
    $user->save();

    return $user->refresh();
}

/** An Instagram comment on a real post, the way the sync stores it. */
function instagramComment(): \App\Models\Interaction
{
    $account = \App\Models\SocialAccount::first() ?? \App\Models\SocialAccount::create([
        'platform' => 'instagram',
        'name' => 'Akun Uji',
        'username' => 'akun_uji',
        'is_active' => true,
    ]);

    // firstOrCreate, not create: (social_account_id, external_id) is unique, so
    // a test that needs two comments on the same post would otherwise fail on
    // the second call rather than get a second comment.
    $media = \App\Models\AccountMedia::firstOrCreate(
        ['social_account_id' => $account->id, 'external_id' => 'MEDIA_TEST_1'],
        [
            'caption' => 'Postingan uji',
            'media_type' => 'IMAGE',
            'permalink' => 'https://instagram.com/p/TESTSHORTCODE/',
            'posted_at' => now()->subDay(),
        ],
    );

    return \App\Models\Interaction::create([
        'channel' => 'instagram',
        'type' => 'comment',
        'direction' => 'inbound',
        'source_type' => $media->getMorphClass(),
        'source_id' => $media->getKey(),
        'external_id' => 'COMMENT_TEST_'.uniqid(),
        'author_handle' => 'mahasiswa123',
        'author_name' => 'Mahasiswa Uji',
        'text' => 'Min saya belum bisa melakukan registrasi',
        'occurred_at' => now()->subHours(3),
        'status' => 'new',
    ]);
}
