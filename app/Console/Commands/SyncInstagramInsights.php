<?php

namespace App\Console\Commands;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Services\Publishing\InstagramInsightsSync;
use App\Support\SchemaGuard;
use Illuminate\Console\Command;

/**
 * Same split as the comment sync: a cheap routine refresh by default, and a
 * resumable backfill under `--lanjut`.
 *
 * The refresh walks from the newest post and stops at the age window, so the
 * hourly schedule costs a handful of calls. The backfill resumes from the
 * account's stored paging cursor and ignores the window — that is the only way
 * past the per-run page ceiling, which otherwise capped the account at 2.000
 * posts no matter how many times the command ran.
 */
class SyncInstagramInsights extends Command
{
    protected $signature = 'accounts:sync-insights
                            {--lanjut : Lanjutkan dari posisi terakhir, menembus batas 2.000 postingan}
                            {--halaman= : Jumlah halaman per run (1 halaman = 50 postingan)}';

    protected $description = 'Tarik postingan Instagram beserta insight-nya dan simpan snapshot harian.';

    public function handle(InstagramInsightsSync $sync): int
    {
        $backfill = (bool) $this->option('lanjut');
        $pages = $this->option('halaman') !== null ? max(1, (int) $this->option('halaman')) : null;

        $this->newLine();

        if ($backfill) {
            // Before the first API call, not after several hundred: the walk
            // reads the cursor (null on a missing column, which Eloquent does
            // not complain about) and only fails when it writes it back.
            $missing = SchemaGuard::missing([
                'social_accounts.media_cursor',
                'social_accounts.media_backfilled_at',
            ]);

            if ($missing !== []) {
                $this->error(SchemaGuard::explain($missing));
                $this->newLine();

                return self::FAILURE;
            }

            $this->line('  Mode: <options=bold>LANJUT</> — menyambung dari posisi terakhir, tanpa batas usia');
            $this->reportCursors();
        } else {
            // Stated up front because the window is the usual explanation for
            // "why are there only N posts": without it the run looks like it
            // failed halfway rather than like it stopped where it was told to.
            $days = (int) config('services.instagram.max_age_days', 90);

            $this->line('  Mode: <options=bold>SEGARKAN</> — dari postingan terbaru');
            $this->line($days > 0
                ? "  Jendela: {$days} hari terakhir (sejak ".now()->subDays($days)->translatedFormat('d M Y').') — ubah lewat INSTAGRAM_MAX_AGE_DAYS'
                : '  Jendela: semua postingan (INSTAGRAM_MAX_AGE_DAYS=0)');
        }

        if ($pages !== null) {
            $this->line(sprintf('  Batas run ini: %d halaman (± %s postingan)', $pages, number_format($pages * 50)));
        }

        $result = $sync->syncAll($pages, backfill: $backfill);

        $this->newLine();
        $this->info(sprintf(
            '  Selesai — %d postingan diperiksa, %d snapshot tersimpan, %d akun gagal.',
            $result['media'],
            $result['snapshots'],
            $result['failed'],
        ));

        if ($backfill) {
            if ($result['done']) {
                $this->line('  Seluruh riwayat akun sudah tertarik — tidak ada halaman tersisa.');
            } else {
                $this->line('  Masih ada sisa. Lanjutkan: <options=bold>php artisan accounts:sync-insights --lanjut</>');
            }
        }

        $this->newLine();

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Where each account's backfill currently stands. */
    private function reportCursors(): void
    {
        $accounts = SocialAccount::active()
            ->where('platform', SocialPlatform::Instagram->value)
            ->orderBy('name')
            ->get();

        foreach ($accounts as $account) {
            $state = match (true) {
                $account->media_backfilled_at !== null => 'selesai '.$account->media_backfilled_at->translatedFormat('d M Y'),
                filled($account->media_cursor) => 'menyambung dari posisi tersimpan',
                default => 'mulai dari postingan terbaru',
            };

            $this->line(sprintf('    @%-24s %s', $account->username, $state));
        }
    }
}
