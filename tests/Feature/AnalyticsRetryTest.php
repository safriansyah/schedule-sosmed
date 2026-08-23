<?php
use App\Enums\{ContentStatus, RoleName, SocialPlatform};
use App\Models\{Content, Schedule, SocialAccount, User};
use App\Services\Publishing\TokenRefresher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

it('renders analytics with side-by-side period comparison', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $this->actingAs($admin)->get(route('analytics.index'))
        ->assertOk()
        ->assertSee('Analytics')
        ->assertSee('Performa per Jam Posting')
        ->assertSee('Postingan Terbaik')
        ->assertSee('Komentar Terbaru')
        ->assertSee('7 hari')
        ->assertSee('30 hari');
});

it('lets every role view analytics', function () {
    foreach (RoleName::cases() as $role) {
        $this->actingAs(User::withRole($role)->firstOrFail())
            ->get(route('analytics.index'))->assertOk();
    }
});

it('puts a failed content back into the publishing queue', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $account = SocialAccount::firstOrCreate(
        ['platform' => SocialPlatform::Instagram, 'external_id' => 'retry-acc'],
        ['name' => 'Retry IG', 'access_token' => 'x', 'is_active' => true],
    );

    $content = Content::create([
        'title' => 'Gagal Terbit', 'status' => ContentStatus::Failed,
        'created_by' => $creative->id, 'scheduled_at' => now()->subHour(),
        'last_error' => 'Token kedaluwarsa',
    ]);

    $schedule = $content->schedules()->create([
        'social_account_id' => $account->id, 'scheduled_at' => now()->subHour(),
        'status' => ContentStatus::Failed, 'attempts' => Schedule::MAX_ATTEMPTS,
        'last_error' => 'Token kedaluwarsa', 'last_attempt_at' => now(),
    ]);

    // Exhausted attempts means the scheduler ignores it.
    expect(Schedule::due()->whereKey($schedule->getKey())->exists())->toBeFalse();

    $this->actingAs($admin)->post(route('contents.retry', $content))->assertRedirect();

    $content->refresh();
    $schedule->refresh();

    expect($content->status)->toBe(ContentStatus::Scheduled)
        ->and($content->last_error)->toBeNull()
        ->and($schedule->attempts)->toBe(0)
        ->and(Schedule::due()->whereKey($schedule->getKey())->exists())->toBeTrue();
});

it('renews a token that is close to expiry', function () {
    Http::fake([
        '*refresh_access_token*' => Http::response([
            'access_token' => 'IGAA-token-baru',
            'token_type' => 'bearer',
            'expires_in' => 5184000,
            'permissions' => 'instagram_business_basic',
        ]),
    ]);

    $account = SocialAccount::create([
        'platform' => SocialPlatform::Instagram, 'name' => 'Hampir Habis',
        'external_id' => 'exp-'.uniqid(), 'access_token' => 'IGAA-lama',
        'is_active' => true, 'token_expires_at' => now()->addDays(3),
    ]);

    expect(app(TokenRefresher::class)->needsRefresh($account))->toBeTrue();

    app(TokenRefresher::class)->refresh($account);

    $fresh = $account->fresh();
    expect($fresh->access_token)->toBe('IGAA-token-baru')
        ->and(now()->diffInDays($fresh->token_expires_at))->toBeGreaterThan(55);
});

it('leaves a healthy token alone', function () {
    $account = SocialAccount::create([
        'platform' => SocialPlatform::Instagram, 'name' => 'Masih Lama',
        'external_id' => 'ok-'.uniqid(), 'access_token' => 'IGAA-sehat',
        'is_active' => true, 'token_expires_at' => now()->addDays(50),
    ]);

    expect(app(TokenRefresher::class)->needsRefresh($account))->toBeFalse();
});
