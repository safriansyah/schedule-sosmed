<?php
use App\Enums\{InteractionType, RoleName, SocialPlatform};
use App\Models\{AccountMedia, Interaction, SocialAccount, User};
use App\Services\Publishing\InstagramProfileLookup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{Cache, Http};

uses(DatabaseTransactions::class);

beforeEach(fn () => Cache::flush());

it('maps the search response into a profile', function () {
    Http::fake(['*/api/ins/monitor/search/*' => Http::response([
        'code' => 0,
        'data' => [[
            'success' => true, 'media_name' => 'luthfierys', 'full_name' => 'luthfi erys',
            'media_url' => 'https://www.instagram.com/luthfierys/',
            'profile_pic_url' => 'https://cdn/x.jpg', 'is_private' => 0, 'is_verified' => false,
            'pk' => null, 'follower_count' => 53, 'following_count' => 637,
            'media_count' => 4, 'profile' => 'Elegance in every moment',
        ]],
        'message' => 'success',
    ])]);

    $p = app(InstagramProfileLookup::class)->find('@luthfierys');

    expect($p['username'])->toBe('luthfierys');
    expect($p['full_name'])->toBe('luthfi erys');
    expect($p['followers'])->toBe(53);
    expect($p['following'])->toBe(637);
    expect($p['media_count'])->toBe(4);
    expect($p['is_private'])->toBeFalse();
    expect($p['bio'])->toBe('Elegance in every moment');
});

it('returns null when the account is not found', function () {
    Http::fake(['*/search/*' => Http::response(['code' => 0, 'data' => [], 'message' => 'ok'])]);
    expect(app(InstagramProfileLookup::class)->find('ghost'))->toBeNull();
});

it('caches the lookup so it only calls the endpoint once', function () {
    Http::fake(['*/search/*' => Http::response([
        'code' => 0, 'data' => [['success' => true, 'media_name' => 'x', 'follower_count' => 1]],
    ])]);

    $svc = app(InstagramProfileLookup::class);
    $svc->find('x');
    $svc->find('x');

    Http::assertSentCount(1);
});

it('renders the profile page with counts and the commenter history', function () {
    Http::fake(['*/search/*' => Http::response([
        'code' => 0, 'data' => [['success' => true, 'media_name' => 'aayulstrr',
            'full_name' => 'Ayu', 'follower_count' => 100, 'following_count' => 50, 'media_count' => 7]],
    ])]);

    $account = SocialAccount::firstOrCreate(
        ['platform' => SocialPlatform::Instagram, 'external_id' => 'prof-acc'],
        ['name' => 'IG', 'access_token' => 'x', 'is_active' => true],
    );
    $media = AccountMedia::create([
        'social_account_id' => $account->id, 'external_id' => 'pm1',
        'permalink' => 'https://instagram.com/p/A/', 'caption' => 'Postingan uji', 'posted_at' => now(),
    ]);
    Interaction::create([
        'channel' => SocialPlatform::Instagram->value,
        'type' => InteractionType::Comment->value,
        'source_type' => AccountMedia::class, 'source_id' => $media->id,
        'external_id' => 'c1', 'author_handle' => 'aayulstrr',
        'text' => 'keren banget', 'occurred_at' => now(),
    ]);

    $this->actingAs(User::withRole(RoleName::SuperAdmin)->firstOrFail())
        ->get(route('monitoring.profile', ['username' => 'aayulstrr']))
        ->assertOk()
        ->assertSee('Ayu')
        ->assertSee('Buka di Instagram')
        ->assertSee('keren banget')
        ->assertSee('Postingan uji');
});
