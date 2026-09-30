<?php

use App\Services\SystemHealth;
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

// Heartbeat. Everything below depends on ONE OS cron entry, and when that
// stops nothing errors — posts simply never publish and comments never sync.
// Stamping the time here is what lets the dashboard say so out loud instead of
// the app looking healthy while quietly doing nothing.
Schedule::call(function () {
    SystemHealth::beat();
})->everyMinute()->name('scheduler-heartbeat');

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

// Public comments (via the unofficial viewer) refreshed every 5 hours. This
// pass deliberately revisits the NEWEST posts, because that is where new
// comments appear.
Schedule::call(function () {
    Artisan::call('accounts:sync-comments');
})->cron('0 */5 * * *')->name('sync-instagram-comments');

/*
| ── Backfill ────────────────────────────────────────────────────────────────
| The two passes above only ever look at recent posts, which is right for
| keeping the inbox current and useless for the 2.200 posts already on the
| account. These fill in the history instead: each run continues from where
| the last stopped, so the archive is covered over a few days without any run
| being big enough to exhaust the API quota or hammer the comment viewer.
|
| Deliberately small and frequent rather than one nightly sweep — a sweep that
| fails halfway loses its whole night, whereas these just resume.
*/

// ~100 posts every 6 hours: the full 2.200 in about six days.
// One page is 50 posts and costs ~50 insight calls, so 2 pages sits well
// inside Instagram's ~200 calls/hour.
Schedule::call(function () {
    Artisan::call('accounts:sync-insights', ['--lanjut' => true, '--halaman' => 2]);
})->cron('20 */6 * * *')->name('backfill-instagram-insights');

// 40 posts every 2 hours through the unofficial viewer. Offset from the
// refresh pass above so the two are never in flight at the same time.
Schedule::call(function () {
    Artisan::call('accounts:sync-comments', ['--lanjut' => true]);
})->cron('40 */2 * * *')->name('backfill-instagram-comments');

// Grab local copies of any avatar or thumbnail we do not have yet. The syncs
// already cache what they fetch; this catches whatever slipped through while
// the CDN signature is still valid — after roughly a fortnight the link is
// dead and the image is unrecoverable.
Schedule::call(function () {
    Artisan::call('media:cache', ['--limit' => 200]);
})->hourly()->name('cache-remote-images');

// Housekeeping for the publishing queue: cancel schedule rows whose content
// went back into the workflow, and fail anything too far past its slot to be
// published safely. Hourly is ample — nothing here is time-critical, and it
// stops the queue drifting out of step with reality.
Schedule::call(function () {
    Artisan::call('schedules:prune', ['--force' => true]);
})->hourly()->name('prune-schedules');

// Classify whatever arrived since the last run. Every 15 minutes rather than
// hourly so a reputational attack is flagged while it still matters — the
// per-run cap in config('crm.ai.per_run_limit') keeps the API quota safe.
Schedule::call(function () {
    Artisan::call('interactions:classify');
})->everyFifteenMinutes()->name('classify-interactions');

// Attach a contact to anything the sync could not match at the time (a network
// blip, a rename). Cheap, and keeps the UID database complete.
Schedule::call(function () {
    Artisan::call('contacts:resolve', ['--limit' => 500]);
})->hourly()->name('resolve-contacts');

// Task deadlines, once each morning. The planner already showed "Terlambat: 3"
// — on a page somebody had to remember to open. Nothing reached out, so a task
// could sail past its deadline with the evidence in plain sight and unread.
// 07:30 local: early enough to act on, late enough that the phone is not
// buzzing at dawn.
// In-process like every other entry here: Schedule::command() spawns a
// subprocess through proc_open, which plenty of shared hosts disable, and it
// would fail silently on exactly the kind of box this deploys to. Double-send
// is prevented by a date stamp inside the command rather than by
// withoutOverlapping(), whose cache mutex can stick and block every later run.
Schedule::call(function () {
    Artisan::call('tasks:remind');
})->dailyAt('07:30')->name('task-reminders');

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
