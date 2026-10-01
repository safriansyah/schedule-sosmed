<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the append-only tables from growing forever.
 *
 * Three tables here only ever gain rows. On an account with ~2.200 posts the
 * insight snapshots alone add a few hundred rows a day, which is nothing this
 * month and a few hundred thousand rows in a couple of years — on a desktop
 * that nobody administers, with no DBA and no monitoring.
 *
 * The retention is deliberately generous rather than tight:
 *
 *   media_metrics / account_metrics — 400 days, so a year-on-year comparison
 *   still has last year's figure to compare against. MetricsComparison derives
 *   growth from these snapshots, so cutting to 90 days would quietly turn
 *   every long-range chart flat.
 *
 *   activities — 365 days. It is the audit trail; a year is the least that is
 *   useful and it is the cheapest of the three to keep.
 *
 * Nothing that holds original content is touched: posts, comments, tickets,
 * students and follow-ups are never pruned. Only derived snapshots and the log.
 */
class DataPrune extends Command
{
    protected $signature = 'data:prune
                            {--metrics=400 : Simpan snapshot metrik berapa hari ke belakang}
                            {--activities=365 : Simpan log aktivitas berapa hari ke belakang}
                            {--dry-run : Tampilkan saja, jangan hapus}
                            {--force : Jangan tanya konfirmasi}';

    protected $description = 'Pangkas snapshot metrik & log aktivitas yang sudah terlalu tua';

    public function handle(): int
    {
        $metricDays = max(30, (int) $this->option('metrics'));
        $activityDays = max(30, (int) $this->option('activities'));

        // Floors, not exact values: a typo like --metrics=1 would otherwise
        // delete the history every chart on the dashboard is drawn from.
        $targets = [
            ['media_metrics', 'captured_at', $metricDays],
            ['account_metrics', 'captured_at', $metricDays],
            ['activities', 'created_at', $activityDays],
        ];

        $this->newLine();

        $total = 0;
        $plan = [];

        foreach ($targets as [$table, $column, $days]) {
            $cutoff = now()->subDays($days);
            $count = DB::table($table)->where($column, '<', $cutoff)->count();
            $kept = DB::table($table)->count() - $count;

            $plan[] = [$table, $column, $cutoff, $count];
            $total += $count;

            $this->line(sprintf(
                '  %-18s simpan %3d hari (sejak %s) — hapus %s, sisa %s',
                $table,
                $days,
                $cutoff->translatedFormat('d M Y'),
                number_format($count),
                number_format($kept),
            ));
        }

        $this->newLine();

        if ($total === 0) {
            $this->info('  Tidak ada yang perlu dipangkas.');
            $this->newLine();

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('  --dry-run: tidak ada yang dihapus.');
            $this->newLine();

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Hapus {$total} baris?", false)) {
            $this->line('  Dibatalkan.');

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($plan as [$table, $column, $cutoff, $count]) {
            if ($count === 0) {
                continue;
            }

            // Deleted in chunks rather than one statement: a single DELETE of
            // a hundred thousand rows holds locks long enough for the web
            // requests happening at the same time to time out.
            do {
                $removed = DB::table($table)
                    ->where($column, '<', $cutoff)
                    ->limit(2000)
                    ->delete();

                $deleted += $removed;
            } while ($removed > 0);
        }

        $this->newLine();
        $this->info("  Selesai — {$deleted} baris dihapus.");
        $this->newLine();

        return self::SUCCESS;
    }
}
