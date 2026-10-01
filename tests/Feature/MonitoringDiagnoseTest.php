<?php

/**
 * monitoring:diagnose exists to explain an empty grid over the phone, so the
 * thing worth testing is that it names the RIGHT cause. A diagnostic that says
 * "all good" while the screen is blank is worse than no diagnostic at all.
 */

use App\Enums\SocialPlatform;
use App\Models\{AccountMedia, SocialAccount};
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function diagnoseAccount(bool $active): SocialAccount
{
    return SocialAccount::create([
        'name' => 'Diag '.uniqid(),
        'username' => 'diag_'.uniqid(),
        'platform' => SocialPlatform::Instagram,
        'external_id' => 'diag-'.uniqid(),
        'access_token' => 'token-diag',
        'is_active' => $active,
    ]);
}

function diagnosePost(SocialAccount $account, ?string $thumbPath = 'thumbnails/x.jpg'): AccountMedia
{
    return AccountMedia::create([
        'social_account_id' => $account->id,
        'external_id' => 'post-'.uniqid(),
        'caption' => 'Uji',
        'media_type' => 'IMAGE',
        'product_type' => 'FEED',
        'thumbnail_url' => 'https://cdn/x.jpg',
        'thumbnail_path' => $thumbPath,
        'posted_at' => now()->subDay(),
    ]);
}

it('blames the inactive account when nothing is active', function () {
    // Every existing account switched off, plus posts that do exist — the exact
    // shape of "2.000 rows and an empty screen".
    SocialAccount::query()->update(['is_active' => false]);
    diagnosePost(diagnoseAccount(active: false));

    $this->artisan('monitoring:diagnose')
        ->expectsOutputToContain('NONAKTIF')
        ->assertFailed();
});

it('spots posts that belong to no active account', function () {
    SocialAccount::query()->update(['is_active' => false]);
    diagnoseAccount(active: true);                  // active, but has no posts
    diagnosePost(diagnoseAccount(active: false));   // posts, but on a dead account

    $this->artisan('monitoring:diagnose')
        ->expectsOutputToContain('tidak satu pun milik akun yang aktif')
        ->assertFailed();
});

it('tells you to cache the photos when none are stored locally', function () {
    SocialAccount::query()->update(['is_active' => false]);
    AccountMedia::query()->update(['thumbnail_path' => null]);
    diagnosePost(diagnoseAccount(active: true), thumbPath: null);

    $this->artisan('monitoring:diagnose')
        ->expectsOutputToContain('media:cache')
        ->assertFailed();
});

it('passes when an active account has posts with photos', function () {
    SocialAccount::query()->update(['is_active' => false]);
    diagnosePost(diagnoseAccount(active: true));

    $this->artisan('monitoring:diagnose')
        ->expectsOutputToContain('siap tampil')
        ->assertSuccessful();
});
