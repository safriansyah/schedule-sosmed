<?php

use App\Enums\ContentStatus;
use App\Models\{Content, Schedule, SocialAccount, User};
use App\Enums\SocialPlatform;
use App\Services\SystemHealth;
use App\Models\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;

uses(DatabaseTransactions::class);

/**
 * The point of these checks is a failure that produces no error: the one OS
 * cron entry stops, and the app carries on looking healthy while publishing
 * nothing. So the tests care most about the DOWN path being loud.
 */
beforeEach(function () {
    Setting::forget(SystemHealth::HEARTBEAT);
});

/** Put the last beat at a chosen moment in the past. */
function beatAt(\Illuminate\Support\Carbon $when): void
{
    Setting::put(SystemHealth::HEARTBEAT, $when->toIso8601String());
}

it('reports the scheduler as down when it has never run', function () {
    $report = app(SystemHealth::class)->report();

    expect($report['ok'])->toBeFalse()
        ->and($report['state'])->toBe('down');

    $scheduler = collect($report['checks'])->firstWhere('name', 'Penjadwal');

    // The hint must name the actual fix, not just the symptom — and on a
    // developer's machine the fix is one command, not a crontab entry.
    expect($scheduler['state'])->toBe('down')
        ->and($scheduler['hint'])->toContain('composer run dev');
});

it('reports the scheduler as healthy right after a beat', function () {
    SystemHealth::beat();

    $scheduler = collect(app(SystemHealth::class)->report()['checks'])
        ->firstWhere('name', 'Penjadwal');

    expect($scheduler['state'])->toBe('ok');
});

it('calls the scheduler down once the heartbeat goes quiet', function () {
    // A minute or two of silence is a slow run, not an outage…
    beatAt(now()->subMinutes(2));
    expect(app(SystemHealth::class)->report()['state'])->toBe('ok');

    // …but hours of it is.
    beatAt(now()->subHours(3));
    expect(app(SystemHealth::class)->report()['state'])->toBe('down');
});

it('lets the worst check decide the headline', function () {
    SystemHealth::beat();

    $account = SocialAccount::firstOrCreate(
        ['platform' => SocialPlatform::Instagram, 'external_id' => 'health-acc'],
        ['name' => 'Health', 'access_token' => 'x', 'is_active' => true],
    );

    $content = Content::create([
        'title' => 'Kedaluwarsa',
        'status' => ContentStatus::Scheduled,
        'created_by' => User::first()->id,
    ]);

    Schedule::create([
        'content_id' => $content->id,
        'social_account_id' => $account->id,
        'scheduled_at' => now()->subDays(30),
        'status' => ContentStatus::Scheduled,
    ]);

    $report = app(SystemHealth::class)->report();

    // Scheduler is fine, but a stale schedule drags the headline to amber —
    // a green banner over a red row would be worse than no banner.
    expect($report['state'])->toBe('warn')
        ->and($report['ok'])->toBeFalse();
});

it('stops flagging a stale schedule once it has been failed', function () {
    SystemHealth::beat();

    $account = SocialAccount::firstOrCreate(
        ['platform' => SocialPlatform::Instagram, 'external_id' => 'health-acc2'],
        ['name' => 'Health2', 'access_token' => 'x', 'is_active' => true],
    );

    $content = Content::create([
        'title' => 'Sudah gagal',
        'status' => ContentStatus::Failed,
        'created_by' => User::first()->id,
    ]);

    Schedule::create([
        'content_id' => $content->id,
        'social_account_id' => $account->id,
        'scheduled_at' => now()->subDays(30),
        'status' => ContentStatus::Failed,
    ]);

    $publishing = collect(app(SystemHealth::class)->report()['checks'])
        ->firstWhere('name', 'Antrean terbit');

    // Already surfaced to the team as failed; repeating it here would leave
    // the banner amber forever with nothing left to do.
    expect($publishing['state'])->toBe('ok');
});

/* -----------------------------------------------------------------
 | The false alarm that started this
 * ----------------------------------------------------------------- */

it('survives a cache clear', function () {
    SystemHealth::beat();

    // Clearing the cache is a routine action with nothing to do with the
    // scheduler. It used to wipe the heartbeat, after which this panel
    // announced the automation dead and told the admin to install cron —
    // while schedule:work was running in the next window.
    Cache::flush();

    $scheduler = collect(app(SystemHealth::class)->report()['checks'])
        ->firstWhere('name', 'Penjadwal');

    expect($scheduler['state'])->toBe('ok')
        ->and(app(SystemHealth::class)->lastRun())->not->toBeNull();
});

it('names the fix that fits the machine it is running on', function () {
    // Advice keyed on the environment alone was wrong for the deployment this
    // actually has: a Windows box on the office network running
    // `composer run dev:lan`, with APP_ENV=production because the app is
    // reachable through a tunnel. That machine has no crontab, and being sent
    // to look for one left the scheduler down while the reader searched.
    //
    // So the OS decides first, and the environment only afterwards.
    expect(SystemHealth::howToStart())->toContain('composer run dev');

    $was = app()->environment();
    app()->detectEnvironment(fn () => 'production');

    try {
        if (PHP_OS_FAMILY === 'Windows') {
            expect(SystemHealth::howToStart())
                ->toContain('composer run dev:lan')
                ->not->toContain('cron');
        } else {
            expect(SystemHealth::howToStart())->toContain('* * * * * php artisan schedule:run');
        }
    } finally {
        app()->detectEnvironment(fn () => $was);
    }
});
