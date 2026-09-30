<?php

namespace App\Jobs;

use App\Services\Publishing\QuickSync;
use App\Services\Publishing\SyncStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The "Sinkron sekarang" button, off the web request.
 *
 * Even the bounded QuickSync makes one API call per post it refreshes, so it
 * takes tens of seconds — long enough that the browser looked frozen and
 * people clicked again. Nothing here needs to happen inside the request: the
 * page reads the result from the database afterwards either way.
 *
 * `tries = 1` on purpose. A retry would double the API calls for a button
 * somebody can simply press again, and a failed sync is not worth burning
 * quota on twice.
 */
class RunQuickSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function handle(QuickSync $sync, SyncStatus $status): void
    {
        $status->running();

        try {
            $result = $sync->run();
        } catch (Throwable $e) {
            $status->failed('Sinkron gagal: '.$e->getMessage());
            Log::warning('Sinkron manual gagal: '.$e->getMessage());

            return;
        }

        $status->done($sync->summary($result));
    }

    /**
     * Reached when the job times out or the worker dies mid-run — handle()'s
     * own catch never sees those, and without this the page would keep
     * reporting "running" until the staleness guard gave up.
     */
    public function failed(?Throwable $e): void
    {
        app(SyncStatus::class)->failed('Sinkron berhenti: '.($e?->getMessage() ?? 'tidak diketahui'));
    }
}
