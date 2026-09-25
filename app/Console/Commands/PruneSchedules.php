<?php

namespace App\Console\Commands;

use App\Enums\ContentStatus;
use App\Models\Schedule;
use App\Services\ActivityLogger;
use App\Services\Publishing\PublishingService;
use Illuminate\Console\Command;

/**
 * Housekeeping for the publishing queue.
 *
 * Two kinds of dead weight collect over time:
 *
 *   Orphans — a curator sends scheduled content back for revision, the content
 *   leaves the Scheduled state, but its schedule row stays behind. Harmless
 *   (the publisher checks content status too) yet it clutters the calendar and
 *   makes "what is actually due?" impossible to answer at a glance.
 *
 *   Stale — a schedule so far past its slot that publishing it now would do
 *   more harm than skipping it. Usually the sign of a cron that stopped.
 *
 * Safe to run repeatedly, so it also lives in the scheduler.
 */
class PruneSchedules extends Command
{
    protected $signature = 'schedules:prune
                            {--force : Jalankan tanpa konfirmasi (untuk cron)}
                            {--dry-run : Tampilkan saja, jangan ubah apa pun}';

    protected $description = 'Bersihkan jadwal terbit yang yatim atau sudah kedaluwarsa';

    public function handle(PublishingService $publisher, ActivityLogger $log): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $orphans = Schedule::orphaned()->with('content:id,title,status')->get();
        $stale = Schedule::stale()
            ->whereHas('content', fn ($q) => $q->where('status', ContentStatus::Scheduled->value))
            ->with('content:id,title')
            ->get();

        if ($orphans->isEmpty() && $stale->isEmpty()) {
            $this->info('Tidak ada jadwal yang perlu dibersihkan.');

            return self::SUCCESS;
        }

        $this->report($orphans, $stale);

        if ($dryRun) {
            $this->newLine();
            $this->comment('Mode --dry-run: tidak ada yang diubah.');

            return self::SUCCESS;
        }

        // Interactive runs confirm; cron passes --force.
        if (! $this->option('force') && ! $this->confirm('Lanjutkan pembersihan?', false)) {
            $this->line('Dibatalkan.');

            return self::SUCCESS;
        }

        $expired = $publisher->expireStale();
        $cancelled = $this->cancelOrphans($orphans, $log);

        $this->newLine();
        $this->info("Selesai — {$cancelled} jadwal yatim dibatalkan, {$expired} jadwal kedaluwarsa ditandai gagal.");

        return self::SUCCESS;
    }

    /**
     * Cancel schedule rows whose content has moved on.
     *
     * The row is cancelled rather than deleted: it is part of the history of
     * that piece of content, and an audit trail with holes in it is worth
     * less than one with cancelled entries.
     *
     * @param  \Illuminate\Support\Collection<int, Schedule>  $orphans
     */
    private function cancelOrphans($orphans, ActivityLogger $log): int
    {
        if (! config('publishing.prune.orphans', true) || $orphans->isEmpty()) {
            return 0;
        }

        foreach ($orphans as $schedule) {
            $schedule->update([
                'status' => ContentStatus::Cancelled,
                'last_error' => 'Dibatalkan otomatis: konten kembali ke alur kerja ('
                    .($schedule->content?->status?->label() ?? 'tidak diketahui').').',
            ]);

            $log->log(
                'schedule.pruned',
                "Jadwal \"{$schedule->content?->title}\" dibatalkan — konten tidak lagi berstatus terjadwal",
                $schedule->content,
                ['scheduled_at' => $schedule->scheduled_at?->toIso8601String()],
            );
        }

        return $orphans->count();
    }

    /** @param \Illuminate\Support\Collection<int, Schedule> $orphans */
    private function report($orphans, $stale): void
    {
        if ($orphans->isNotEmpty()) {
            $this->newLine();
            $this->line('  <fg=yellow>Jadwal yatim</> — kontennya sudah kembali ke alur kerja:');

            $this->table(
                ['Konten', 'Jadwal', 'Status konten sekarang'],
                $orphans->map(fn (Schedule $s) => [
                    str($s->content?->title ?? '(hilang)')->limit(34),
                    $s->scheduled_at?->translatedFormat('d M Y H:i') ?? '—',
                    $s->content?->status?->label() ?? '—',
                ])->all(),
            );
        }

        if ($stale->isNotEmpty()) {
            $this->newLine();
            $this->line('  <fg=red>Jadwal kedaluwarsa</> — terlalu lama lewat untuk diterbitkan sekarang:');

            $this->table(
                ['Konten', 'Jadwal', 'Terlambat'],
                $stale->map(fn (Schedule $s) => [
                    str($s->content?->title ?? '(hilang)')->limit(34),
                    $s->scheduled_at?->translatedFormat('d M Y H:i') ?? '—',
                    number_format($s->hoursLate(), 1).' jam',
                ])->all(),
            );

            $this->line('  <fg=gray>Batas aman: '.config('publishing.max_delay_hours').' jam. '
                .'Konten ditandai gagal agar bisa ditinjau dan dijadwalkan ulang.</>');
        }
    }
}
