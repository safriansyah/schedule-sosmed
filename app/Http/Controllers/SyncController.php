<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Jobs\RunQuickSync;
use App\Models\AccountMetric;
use App\Services\Publishing\SyncStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * On-demand sync — the button that fetches fresh numbers right now instead of
 * waiting for the schedule.
 *
 * The work is queued, not done here. It used to run inline, which meant the
 * request held open for as long as the Instagram API took: one call per post
 * refreshed, so tens of seconds on a real account. The page looked frozen and
 * people pressed the button again, starting a second sweep on top of the first.
 */
class SyncController extends Controller
{
    public function __construct(private readonly SyncStatus $status) {}

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize(Permission::ViewMonitoring->value);

        if ($this->status->isBusy()) {
            return $this->answer($request, 'Sinkron sedang berjalan — tunggu sampai selesai.');
        }

        // Marked queued BEFORE dispatch, so a second click in the same second
        // cannot slip through the check above.
        $this->status->queued();

        RunQuickSync::dispatch();

        return $this->answer($request, 'Sinkron dijalankan di latar belakang — hasilnya muncul sebentar lagi.');
    }

    /**
     * The button posts with fetch and polls for the outcome, so it wants JSON.
     * The plain form POST is kept working as well: the button is a shared
     * component and JavaScript is not guaranteed to have loaded.
     */
    private function answer(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json($this->status->current() + ['message' => $message]);
        }

        return back()->with('toast', ['message' => $message, 'type' => 'info']);
    }

    /** Polled by the button while a run is in flight. */
    public function show(): JsonResponse
    {
        $this->authorize(Permission::ViewMonitoring->value);

        return response()->json($this->status->current() + [
            'last_synced_at' => self::lastSyncedAt()?->diffForHumans(),
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
