<?php

namespace App\Services\Publishing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What the "Sinkron sekarang" button is currently doing.
 *
 * The button used to run the whole sync inside the POST request, so the page
 * simply hung — a minute or more on an account with a few hundred posts,
 * because each post costs its own API call. Now the work goes to the queue and
 * the page asks this what is happening.
 *
 * Kept in the cache rather than in `settings`: it changes on every run and is
 * worthless once it is a few minutes old, while settings are cached forever
 * and flushed on write. The cache store here is the database, so the state
 * survives a restart of the web server — which matters, because the machine
 * this runs on is a desktop that gets switched off.
 *
 * Every state carries a timestamp, because the failure that actually happens
 * is not an exception — it is nobody running the queue worker, in which case
 * the job sits in the table and NOTHING reports anything at all. The staleness
 * checks below are what turn that silence into a message.
 */
class SyncStatus
{
    private const KEY = 'sync.now.state';

    /** Long enough to outlive any run, short enough to forget yesterday's. */
    private const TTL_MINUTES = 180;

    /**
     * After this long unstarted, assume nothing is going to pick the job up.
     * A queue worker takes a second or two, so this is generous.
     */
    private const QUEUE_PATIENCE_SECONDS = 45;

    /** After this long running, assume the worker died mid-job. */
    private const RUN_PATIENCE_MINUTES = 15;

    public function queued(): void
    {
        $this->put(['status' => 'queued', 'message' => 'Menunggu antrean…']);
    }

    public function running(): void
    {
        $this->put(['status' => 'running', 'message' => 'Mengambil data dari Instagram…']);
    }

    public function done(string $message): void
    {
        $this->put(['status' => 'done', 'message' => $message]);
    }

    public function failed(string $message): void
    {
        $this->put(['status' => 'failed', 'message' => $message]);
    }

    public function clear(): void
    {
        Cache::forget(self::KEY);
    }

    /**
     * True while a run is in flight, so a second click cannot start another.
     *
     * A stuck state must not block the button forever — the worker is killed
     * whenever the person closes the `composer run dev:lan` window, which on
     * this setup is most days.
     */
    public function isBusy(): bool
    {
        $state = $this->current();

        return in_array($state['status'], ['queued', 'running'], true) && ! $state['stalled'];
    }

    /**
     * @return array{status:string, message:string, at:?string, since:?int, stalled:bool, hint:?string}
     */
    public function current(): array
    {
        $state = Cache::get(self::KEY);

        if (! is_array($state)) {
            return [
                'status' => 'idle',
                'message' => '',
                'at' => null,
                'since' => null,
                'stalled' => false,
                'hint' => null,
            ];
        }

        $at = Carbon::parse($state['at']);
        $since = (int) $at->diffInSeconds(now());

        $stalled = match ($state['status']) {
            'queued' => $since > self::QUEUE_PATIENCE_SECONDS,
            'running' => $since > self::RUN_PATIENCE_MINUTES * 60,
            default => false,
        };

        return [
            'status' => $state['status'],
            'message' => $state['message'],
            'at' => $at->toIso8601String(),
            'since' => $since,
            'stalled' => $stalled,
            // The single most likely cause, named outright, because the
            // symptom (a button that spins and never finishes) says nothing.
            'hint' => $stalled && $state['status'] === 'queued'
                ? 'Antrean tidak jalan. Pastikan "composer run dev:lan" masih terbuka, atau jalankan "php artisan queue:work".'
                : ($stalled ? 'Proses sinkron berhenti di tengah jalan. Coba jalankan lagi.' : null),
        ];
    }

    private function put(array $state): void
    {
        Cache::put(self::KEY, $state + ['at' => now()->toIso8601String()], now()->addMinutes(self::TTL_MINUTES));
    }
}
