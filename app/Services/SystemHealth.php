<?php

namespace App\Services;

use App\Enums\ContentStatus;
use App\Models\Interaction;
use App\Models\Schedule;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Is the automation actually running?
 *
 * Everything scheduled in this app hangs off ONE OS cron entry. When that
 * stops, nothing errors and no page breaks — posts simply never publish,
 * comments never sync, and nothing is ever classified. The system looks fine
 * while quietly doing nothing, which is the worst kind of failure.
 *
 * This turns that silence into something visible.
 */
class SystemHealth
{
    /**
     * Where the scheduler stamps that it is alive, once a minute.
     *
     * A SETTING, not a cache entry. It used to live in the cache, and that was
     * wrong in a way that took a false alarm to notice: `php artisan
     * cache:clear` is a routine, unrelated action, and it wiped the heartbeat
     * — after which this panel announced "Belum pernah berdetak" and told the
     * admin to install a cron job, while the scheduler was running perfectly
     * two windows away. Whether the automation is alive is a fact about the
     * system, not a cached computation, so clearing a cache must not be able
     * to change the answer.
     */
    public const HEARTBEAT = 'scheduler.last_run';

    /**
     * Minutes of silence before we call the scheduler down.
     *
     * The heartbeat fires every minute; five gives room for a slow run or a
     * brief restart without crying wolf.
     */
    private const STALE_AFTER_MINUTES = 5;

    /** Called by the scheduler itself, once a minute. */
    public static function beat(): void
    {
        Setting::put(self::HEARTBEAT, now()->toIso8601String());
    }

    public function lastRun(): ?Carbon
    {
        $stamp = Setting::get(self::HEARTBEAT);

        return $stamp ? Carbon::parse($stamp) : null;
    }

    /**
     * How to start the scheduler, phrased for the machine this is running on.
     *
     * On a developer's Laragon box "add a line to crontab" is advice nobody
     * can act on; the answer there is one command that starts the server, the
     * queue and the scheduler together. Getting this wrong sends someone
     * hunting for a cron daemon that was never the problem.
     */
    public static function howToStart(): string
    {
        // Keyed on the OPERATING SYSTEM first, not on the environment name.
        //
        // Keying on isProduction() alone was wrong for the deployment this
        // actually has: a Windows machine on the office network, running
        // `composer run dev:lan`, with APP_ENV=production set because the app
        // is reachable from the internet through a tunnel. That box has no
        // crontab, so "add a line to cron" sent the reader hunting for a
        // daemon Windows does not have — and the scheduler stayed down while
        // they looked.
        if (PHP_OS_FAMILY === 'Windows') {
            return 'Jalankan `composer run dev:lan` — satu perintah itu menyalakan server, queue, '
                .'dan penjadwal sekaligus. Jendelanya harus tetap terbuka; untuk server yang menyala '
                .'terus, pasang lewat Task Scheduler.';
        }

        return app()->isProduction()
            ? 'Tambahkan satu baris cron di server: * * * * * php artisan schedule:run'
            : 'Jalankan `composer run dev` — satu perintah itu menyalakan server, queue, dan penjadwal sekaligus.';
    }

    /**
     * One overall verdict plus the detail behind it.
     *
     * @return array{ok:bool, state:string, label:string, detail:string, last_run:?Carbon, checks:array<int, array<string, mixed>>}
     */
    public function report(): array
    {
        $lastRun = $this->lastRun();
        $checks = [
            $this->schedulerCheck($lastRun),
            $this->classifierCheck(),
            $this->publishingCheck(),
            $this->queueCheck(),
        ];

        // The worst check decides the headline — a green banner above a red
        // row would be worse than no banner.
        $worst = collect($checks)->contains(fn ($c) => $c['state'] === 'down') ? 'down'
            : (collect($checks)->contains(fn ($c) => $c['state'] === 'warn') ? 'warn' : 'ok');

        return [
            'ok' => $worst === 'ok',
            'state' => $worst,
            'label' => match ($worst) {
                'ok' => 'Otomasi berjalan normal',
                'warn' => 'Otomasi berjalan, ada yang perlu diperiksa',
                default => 'Otomasi TIDAK berjalan',
            },
            'detail' => match ($worst) {
                'ok' => 'Semua tugas terjadwal aktif.',
                'warn' => 'Sistem jalan, tapi ada bagian yang tidak sehat.',
                default => 'Penjadwal tidak berdetak — tidak ada yang terbit, tersinkron, atau dinilai.',
            },
            'last_run' => $lastRun,
            'checks' => $checks,
        ];
    }

    /* -----------------------------------------------------------------
     | Individual checks
     * ----------------------------------------------------------------- */

    private function schedulerCheck(?Carbon $lastRun): array
    {
        if ($lastRun === null) {
            // The stamp is durable now, so "never" really does mean never —
            // this is a machine where the scheduler has not been started yet.
            // It also covers the first 60 seconds after starting it, since the
            // scheduler only acts on the minute; saying so stops someone
            // reconfiguring a cron job that was about to tick anyway.
            return $this->check('Penjadwal', 'down',
                'Belum pernah berdetak',
                self::howToStart().' Detak pertama muncul di pergantian menit berikutnya.');
        }

        $minutes = $lastRun->diffInMinutes(now());

        if ($minutes > self::STALE_AFTER_MINUTES) {
            return $this->check('Penjadwal', 'down',
                'Berhenti — terakhir '.$lastRun->diffForHumans(),
                'Tidak ada konten yang terbit dan komentar tidak tersinkron. '.self::howToStart());
        }

        return $this->check('Penjadwal', 'ok', 'Aktif — '.$lastRun->diffForHumans());
    }

    private function classifierCheck(): array
    {
        $driver = (string) config('crm.ai.driver', 'rule');
        $backlog = Interaction::inbound()->unclassified()->count();

        // A backlog only matters if it is not being worked through; the job
        // runs every fifteen minutes and caps at per_run_limit.
        if ($backlog > (int) config('crm.ai.per_run_limit', 200) * 3) {
            return $this->check('Klasifikasi komentar', 'warn',
                number_format($backlog).' menunggu dinilai',
                'Antrean menumpuk — periksa penjadwal atau kuota penyedia AI.');
        }

        return $this->check(
            'Klasifikasi komentar',
            'ok',
            $driver === 'rule' ? 'Kamus offline' : 'AI: '.$driver,
            $backlog > 0 ? number_format($backlog).' menunggu giliran' : null,
        );
    }

    private function publishingCheck(): array
    {
        // Only rows still waiting to publish count as a problem. One already
        // marked failed has been surfaced to the team; repeating it here would
        // leave the banner amber forever with nothing left to do.
        $stale = Schedule::stale()
            ->whereHas('content', fn ($q) => $q->where('status', ContentStatus::Scheduled->value))
            ->count();
        $orphans = Schedule::orphaned()->count();

        if ($stale > 0) {
            return $this->check('Antrean terbit', 'warn',
                $stale.' jadwal kedaluwarsa',
                'Terlalu lama lewat untuk diterbitkan otomatis — perlu dijadwalkan ulang.');
        }

        if ($orphans > 0) {
            return $this->check('Antrean terbit', 'warn',
                $orphans.' jadwal yatim',
                'Kontennya sudah kembali ke alur kerja. Jalankan: php artisan schedules:prune');
        }

        $upcoming = Schedule::where('scheduled_at', '>', now())
            ->whereHas('content', fn ($q) => $q->where('status', 'scheduled'))
            ->count();

        return $this->check('Antrean terbit', 'ok',
            $upcoming > 0 ? $upcoming.' menunggu jadwal' : 'Tidak ada antrean');
    }

    private function queueCheck(): array
    {
        // Only meaningful on a database queue; other drivers have no table to
        // count and are reported as not applicable rather than guessed at.
        if (config('queue.default') !== 'database') {
            return $this->check('Queue', 'ok', 'Driver: '.config('queue.default'));
        }

        $failed = DB::table('failed_jobs')->count();

        if ($failed > 0) {
            return $this->check('Queue', 'warn', $failed.' job gagal',
                'Periksa dengan: php artisan queue:failed');
        }

        $pending = DB::table('jobs')->count();

        return $this->check('Queue', 'ok', $pending > 0 ? $pending.' job menunggu' : 'Kosong');
    }

    /** @return array<string, mixed> */
    private function check(string $name, string $state, string $value, ?string $hint = null): array
    {
        return compact('name', 'state', 'value', 'hint');
    }
}
