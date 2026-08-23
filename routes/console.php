<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Publishing runs in-process (Artisan::call) rather than via
| Schedule::command(), because many shared hosts disable proc_open — which the
| subprocess-based scheduler requires.
|
| Overlap is prevented in the data layer (a schedule is stamped before it is
| attempted) rather than with withoutOverlapping(), whose cache mutex can get
| stuck and silently block every later run.
|
| All of this only fires if ONE OS cron runs `php artisan schedule:run` every
| minute. See the note at the bottom.
*/

// Publish anything whose scheduled time has arrived. Must be timely.
Schedule::call(function () {
    Artisan::call('content:publish-due');
})->everyMinute()->name('publish-due-content');

// Account counters (followers / follows / posts) — one cheap API call per
// account, so it can run every minute to keep the dashboard numbers live.
// Snapshots are keyed per hour/day, so frequent runs just refresh the current
// figures rather than piling up rows.
Schedule::call(function () {
    Artisan::call('accounts:sync-metrics');
})->everyMinute()->name('sync-account-metrics');

// Per-post insights (likes / views / reach / saves) — ONE API call per post,
// so this stays hourly to respect Instagram's rate limit (~200 calls/hour).
// Running it every minute would exhaust the quota and get the account throttled.
Schedule::call(function () {
    Artisan::call('accounts:sync-insights');
})->hourly()->name('sync-instagram-insights');

// Long-lived Instagram tokens last 60 days; renew well before they lapse.
Schedule::call(function () {
    Artisan::call('accounts:refresh-tokens');
})->dailyAt('02:00')->name('refresh-account-tokens');

// Public comments (via the unofficial viewer) refreshed every 5 hours.
Schedule::call(function () {
    Artisan::call('accounts:sync-comments');
})->cron('0 */5 * * *')->name('sync-instagram-comments');

/*
| ─────────────────────────────────────────────────────────────────────────────
| SERVER SETUP — add exactly ONE cron entry on the host (crontab -e):
|
|   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
|
| Laravel's scheduler then decides which of the tasks above are due each minute.
| You do NOT create a separate OS cron per task.
| ─────────────────────────────────────────────────────────────────────────────
*/
