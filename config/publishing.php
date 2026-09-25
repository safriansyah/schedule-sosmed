<?php

/*
|--------------------------------------------------------------------------
| Publishing safety
|--------------------------------------------------------------------------
|
| Guards around the automatic publisher. These exist because the scheduler
| is only as reliable as the one OS cron entry behind it — and the failure
| mode of a stopped cron is not "nothing happens", it is "everything happens
| at once when it comes back".
|
*/

return [

    /*
    | How late a post may still be published, in hours.
    |
    | Without this, a cron that has been down for a month republishes every
    | missed post the moment it restarts — a promo for an event that already
    | happened goes live as if it were current. Anything later than this is
    | marked failed instead, so a human decides whether it is still relevant.
    |
    | 24 hours tolerates an overnight outage or a slow deploy while stopping
    | anything genuinely stale. Set to 0 to disable the guard entirely (not
    | recommended).
    */
    'max_delay_hours' => (int) env('PUBLISH_MAX_DELAY_HOURS', 24),

    /*
    | Housekeeping for schedule rows whose content has moved on.
    |
    | When a curator sends scheduled content back for revision, the content
    | leaves the Scheduled state but its schedule row stays behind. Those rows
    | are already harmless — the publisher checks the content status too — but
    | they accumulate, confuse the calendar, and make "what is due?" hard to
    | answer honestly. The prune command clears them.
    */
    'prune' => [
        // Schedules whose content is no longer schedulable are cancelled.
        'orphans' => (bool) env('PUBLISH_PRUNE_ORPHANS', true),
    ],

];
