<?php
use App\Enums\{ContentStatus, RoleName, SocialPlatform};
use App\Models\{Content, Schedule, SocialAccount, User};
use App\Services\Publishing\PublishingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

function scheduledContent(string $token = 'IGAA-valid'): Schedule {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();

    $account = SocialAccount::create([
        'platform' => SocialPlatform::Instagram, 'name' => 'IG Pub',
        'username' => 'ig_pub', 'external_id' => 'pub-'.uniqid(),
        'access_token' => $token, 'is_active' => true,
    ]);

    $content = Content::create([
        'title' => 'Publish test', 'caption' => 'Halo', 'hashtags' => '#uji',
        'status' => ContentStatus::Scheduled, 'created_by' => $creative->id,
        'scheduled_at' => now()->subMinute(),
    ]);
    $content->media()->create(['type' => 'image', 'disk' => 'public', 'path' => 'a.jpg', 'size' => 10]);

    return $content->schedules()->create([
        'social_account_id' => $account->id,
        'scheduled_at' => now()->subMinute(),
        'status' => ContentStatus::Scheduled,
    ]);
}

it('publishes a due schedule through the two-step Instagram flow', function () {
    Http::fake([
        '*/me/media' => Http::response(['id' => 'container-1']),
        '*/container-1*' => Http::response(['status_code' => 'FINISHED']),
        '*/me/media_publish' => Http::response(['id' => 'post-99']),
        '*/post-99*' => Http::response(['permalink' => 'https://instagram.com/p/abc']),
    ]);

    $schedule = scheduledContent();
    $result = app(PublishingService::class)->publishDue();

    expect($result['published'])->toBe(1)->and($result['failed'])->toBe(0);

    $schedule->refresh();
    expect($schedule->status)->toBe(ContentStatus::Published)
        ->and($schedule->external_id)->toBe('post-99')
        ->and($schedule->permalink)->toBe('https://instagram.com/p/abc')
        ->and($schedule->content->fresh()->status)->toBe(ContentStatus::Published);
});

it('records a readable error when the token is rejected', function () {
    Http::fake([
        '*' => Http::response(['error' => ['message' => 'Error validating access token', 'type' => 'OAuthException']], 400),
    ]);

    $schedule = scheduledContent();
    $result = app(PublishingService::class)->publishDue();

    expect($result['failed'])->toBe(1);

    $schedule->refresh();
    expect($schedule->status)->toBe(ContentStatus::Failed)
        ->and($schedule->last_error)->toContain('kedaluwarsa')
        ->and($schedule->attempts)->toBe(1);
});

it('does not pick up a schedule that another run just claimed', function () {
    Http::fake(['*' => Http::response(['id' => 'x'])]);

    $schedule = scheduledContent();
    expect($schedule->claim())->toBeTrue();      // first run claims it
    expect($schedule->fresh()->claim())->toBeFalse(); // second run is locked out

    expect(app(PublishingService::class)->publishDue()['attempted'])->toBe(0);
});

it('stops retrying after the attempt ceiling', function () {
    $schedule = scheduledContent();
    $schedule->forceFill(['attempts' => Schedule::MAX_ATTEMPTS, 'last_attempt_at' => null])->save();

    expect(Schedule::due()->whereKey($schedule->getKey())->exists())->toBeFalse();
});
