<?php

use App\Enums\ContentStatus;
use App\Enums\SocialPlatform;
use App\Models\{Content, Schedule, SocialAccount, User};
use App\Services\Publishing\PublishingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

/**
 * Guards around the automatic publisher.
 *
 * The scenario these exist for is mundane and easy to miss: the one OS cron
 * entry behind the scheduler stops for a while. The failure mode is not
 * "nothing happens" — it is "everything happens at once when it comes back",
 * and a month-old promo going live as if it were current is worse than it
 * never publishing.
 */
function publishAccount(): SocialAccount
{
    return SocialAccount::firstOrCreate(
        ['platform' => SocialPlatform::Instagram, 'external_id' => 'safety-acc'],
        ['name' => 'Safety', 'access_token' => 'x', 'is_active' => true],
    );
}

function scheduleAt(string $when, ContentStatus $contentStatus = ContentStatus::Scheduled): Schedule
{
    $content = Content::create([
        'title' => 'Uji '.uniqid(),
        'status' => $contentStatus,
        'created_by' => User::first()->id,
    ]);

    return Schedule::create([
        'content_id' => $content->id,
        'social_account_id' => publishAccount()->id,
        'scheduled_at' => now()->parse($when),
        'status' => ContentStatus::Scheduled,
    ]);
}

it('publishes something that is only a little late', function () {
    $schedule = scheduleAt('-2 hours');

    expect(Schedule::due()->whereKey($schedule->id)->exists())->toBeTrue();
});

it('refuses to publish something far past its slot', function () {
    // A cron that has been down for a month.
    $schedule = scheduleAt('-30 days');

    expect(Schedule::due()->whereKey($schedule->id)->exists())->toBeFalse()
        ->and(Schedule::stale()->whereKey($schedule->id)->exists())->toBeTrue();
});

it('honours the configured delay window', function () {
    config()->set('publishing.max_delay_hours', 72);

    $schedule = scheduleAt('-48 hours');

    // Inside a 72-hour window this is still publishable…
    expect(Schedule::due()->whereKey($schedule->id)->exists())->toBeTrue();

    // …but not inside a 24-hour one.
    config()->set('publishing.max_delay_hours', 24);
    expect(Schedule::due()->whereKey($schedule->id)->exists())->toBeFalse();
});

it('can have the guard turned off entirely', function () {
    config()->set('publishing.max_delay_hours', 0);

    $schedule = scheduleAt('-30 days');

    expect(Schedule::due()->whereKey($schedule->id)->exists())->toBeTrue()
        ->and(Schedule::stale()->whereKey($schedule->id)->exists())->toBeFalse();
});

it('fails a stale schedule with a reason a human can act on', function () {
    $schedule = scheduleAt('-30 days');

    $expired = app(PublishingService::class)->expireStale();

    expect($expired)->toBeGreaterThanOrEqual(1);

    $schedule->refresh();

    expect($schedule->status)->toBe(ContentStatus::Failed)
        ->and($schedule->last_error)->toContain('terlambat')
        // The content follows, so it shows as failed in the UI rather than
        // sitting in "Terjadwal" forever.
        ->and($schedule->content->refresh()->status)->toBe(ContentStatus::Failed);
});

it('never publishes a stale post even when the publisher runs', function () {
    // Any HTTP call here would mean something slipped through the guard.
    Http::fake();

    scheduleAt('-30 days');

    $result = app(PublishingService::class)->publishDue();

    expect($result['published'])->toBe(0)
        ->and($result['expired'])->toBeGreaterThanOrEqual(1);

    Http::assertNothingSent();
});

/* -----------------------------------------------------------------
 | Orphans — schedules whose content moved on
 * ----------------------------------------------------------------- */

it('spots a schedule left behind when content goes back for revision', function () {
    $schedule = scheduleAt('+1 day', ContentStatus::Revision);

    expect(Schedule::orphaned()->whereKey($schedule->id)->exists())->toBeTrue();
});

it('does not treat a healthy scheduled post as an orphan', function () {
    $schedule = scheduleAt('+1 day');

    expect(Schedule::orphaned()->whereKey($schedule->id)->exists())->toBeFalse();
});

it('cancels orphans without deleting the history', function () {
    $schedule = scheduleAt('+1 day', ContentStatus::WaitingApproval);

    $this->artisan('schedules:prune', ['--force' => true])->assertSuccessful();

    $schedule->refresh();

    // Cancelled, not deleted: the row is part of that content's history.
    expect($schedule->status)->toBe(ContentStatus::Cancelled)
        ->and($schedule->exists)->toBeTrue()
        ->and($schedule->last_error)->toContain('kembali ke alur kerja');
});

it('leaves everything alone in dry-run mode', function () {
    $schedule = scheduleAt('+1 day', ContentStatus::WaitingApproval);

    $this->artisan('schedules:prune', ['--dry-run' => true])->assertSuccessful();

    expect($schedule->refresh()->status)->toBe(ContentStatus::Scheduled);
});

it('is safe to run when there is nothing to clean', function () {
    $this->artisan('schedules:prune', ['--force' => true])->assertSuccessful();
    $this->artisan('schedules:prune', ['--force' => true])->assertSuccessful();
});
