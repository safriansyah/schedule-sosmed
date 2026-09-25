<?php

use App\Enums\RoleName;
use App\Enums\SocialPlatform;
use App\Models\{SocialAccount, User};
use App\Services\Media\RemoteImageCache;
use App\Services\Publishing\AccountMetricsSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

/**
 * The account profile picture was broken for a subtle reason: `avatar_url` was
 * written ONLY when the account was verified, and Instagram's signed links die
 * within days. So the picture was stale almost immediately and nothing ever
 * refreshed it — the page rendered a bare <img> at a dead URL, which is the
 * browser's broken-image icon.
 *
 * Two things had to be true to fix it, and both are pinned here: the URL is
 * refreshed on every sync, and a dead link never reaches the reader as a
 * broken glyph.
 */
function avatarAccount(): SocialAccount
{
    return SocialAccount::firstOrCreate(
        ['platform' => SocialPlatform::Instagram, 'external_id' => 'avatar-acc'],
        ['name' => 'Avatar Test', 'access_token' => 'token', 'is_active' => true],
    );
}

it('refreshes the profile picture on every metrics sync, not just at verification', function () {
    Storage::fake('public');

    Http::fake([
        'graph.instagram.com/*' => Http::response([
            'followers_count' => 1200,
            'follows_count' => 40,
            'media_count' => 9,
            'profile_picture_url' => 'https://cdn.example/fresh.jpg?oe=FFFFFFFF',
        ]),
        'cdn.example/*' => Http::response("\xFF\xD8\xFF\xE0".str_repeat('x', 200), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $account = avatarAccount();
    $account->forceFill(['avatar_url' => 'https://cdn.example/expired.jpg', 'avatar_path' => null])->save();

    app(AccountMetricsSync::class)->sync($account);

    $account->refresh();

    expect($account->avatar_url)->toBe('https://cdn.example/fresh.jpg?oe=FFFFFFFF')
        ->and($account->avatar_path)->not->toBeNull();

    Storage::disk('public')->assertExists($account->avatar_path);
});

it('serves the account picture from our own disk once cached', function () {
    $account = avatarAccount();
    $account->forceFill(['avatar_url' => 'https://cdn.example/x.jpg', 'avatar_path' => 'cache/accounts/ab/abc.jpg'])->save();

    expect($account->avatar())->toContain('cache/accounts/ab/abc.jpg')
        ->and($account->avatar())->not->toContain('cdn.example');
});

it('falls back to the remote URL when nothing is cached yet', function () {
    $account = avatarAccount();
    $account->forceFill(['avatar_url' => 'https://cdn.example/live.jpg', 'avatar_path' => null])->save();

    expect($account->avatar())->toBe('https://cdn.example/live.jpg');
});

/* -----------------------------------------------------------------
 | A dead link must never render as a broken image
 * ----------------------------------------------------------------- */

it('always draws a fallback underneath a remote image', function () {
    $account = avatarAccount();
    $account->forceFill(['avatar_url' => 'https://cdn.example/dead.jpg', 'avatar_path' => null])->save();

    $html = view('components.account-avatar', ['account' => $account])->render();

    // The platform badge is rendered unconditionally, so an image that fails
    // simply uncovers it…
    expect($html)->toContain($account->platform->color())
        // …which only works because the image removes itself on error.
        ->and($html)->toContain('onerror');
});

it('renders the fallback even when there is no image at all', function () {
    $account = avatarAccount();
    $account->forceFill(['avatar_url' => null, 'avatar_path' => null])->save();

    $html = view('components.account-avatar', ['account' => $account])->render();

    expect($html)->toContain($account->platform->color())
        ->and($html)->not->toContain('<img');
});

it('gives post thumbnails the same treatment', function () {
    $withImage = view('components.remote-image', ['src' => 'https://cdn.example/dead.jpg'])->render();
    $without = view('components.remote-image', ['src' => null, 'label' => 'Foto belum tersimpan'])->render();

    expect($withImage)->toContain('onerror')
        ->and($without)->not->toContain('<img')
        ->and($without)->toContain('Foto belum tersimpan');
});

it('shows the account picture on every page that displays accounts', function () {
    $account = avatarAccount();
    $account->forceFill(['avatar_path' => 'cache/accounts/ab/abc.jpg'])->save();

    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    foreach (['accounts.index', 'monitoring.index', 'dashboard'] as $route) {
        $this->actingAs($admin)->get(route($route))->assertOk();
    }
});
