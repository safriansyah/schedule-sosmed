<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\AccountMetric;
use App\Services\Publishing\AccountMetricsSync;
use App\Services\Publishing\InstagramCommentSync;
use App\Services\Publishing\InstagramInsightsSync;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * On-demand sync — the button that fetches fresh numbers right now instead of
 * waiting for the nightly schedule. Rate-limited at the route to protect the
 * Instagram API quota.
 */
class SyncController extends Controller
{
    public function __construct(
        private readonly AccountMetricsSync $metrics,
        private readonly InstagramInsightsSync $insights,
        private readonly InstagramCommentSync $comments,
    ) {}

    public function store(): RedirectResponse
    {
        $this->authorize(Permission::ViewMonitoring->value);

        try {
            $accounts = $this->metrics->syncAll();
            $posts = $this->insights->syncAll();
            // Bounded so the synchronous request stays responsive; the full
            // sweep runs on the 5-hourly schedule.
            $comments = $this->comments->syncAll(maxPostsOverride: 10);
        } catch (Throwable $e) {
            return back()->with('toast', [
                'message' => 'Sinkron gagal: '.$e->getMessage(),
                'type' => 'error',
            ]);
        }

        $message = sprintf(
            'Sinkron selesai — %d akun, %d postingan, %d snapshot baru, %d komentar.',
            $accounts['synced'],
            $posts['media'],
            $posts['snapshots'],
            $comments['comments'],
        );

        return back()->with('toast', [
            'message' => $message,
            'type' => $accounts['failed'] + $posts['failed'] > 0 ? 'warning' : 'success',
        ]);
    }

    /** When the data was last refreshed — shown next to the button. */
    public static function lastSyncedAt(): ?\Illuminate\Support\Carbon
    {
        return AccountMetric::max('captured_at')
            ? \Illuminate\Support\Carbon::parse(AccountMetric::max('captured_at'))
            : null;
    }
}
