<?php

namespace App\Console\Commands;

use App\Services\Publishing\InstagramCommentSync;
use App\Support\SchemaGuard;
use Illuminate\Console\Command;

/**
 * Two jobs behind one command, because they are the same work aimed at
 * different posts.
 *
 * Without `--lanjut` it refreshes the newest posts — where new comments
 * actually appear — and revisits them on purpose.
 *
 * With `--lanjut` it works backwards through history, taking only posts whose
 * comments have never been fetched. That is the mode that eventually covers
 * all 2.200 posts: each run picks up where the last one stopped, so running it
 * repeatedly makes progress instead of redoing the same 40 posts forever.
 */
class SyncInstagramComments extends Command
{
    protected $signature = 'accounts:sync-comments
                            {--lanjut : Lanjutkan ke postingan berikutnya yang belum pernah diambil komentarnya}
                            {--posts= : Jumlah postingan per putaran (default dari DOLPHINRADAR_MAX_POSTS)}
                            {--putaran=1 : Berapa kali batch dijalankan dalam sekali perintah}
                            {--jeda=3 : Jeda detik antar putaran, supaya tidak dianggap membanjiri}
                            {--status : Tampilkan posisi saja, tanpa menyentuh jaringan}';

    protected $description = 'Tarik komentar publik dari postingan (via comment viewer) dan simpan.';

    public function handle(InstagramCommentSync $sync): int
    {
        $backfill = (bool) $this->option('lanjut');
        $perRound = $this->option('posts') !== null ? max(1, (int) $this->option('posts')) : null;
        $rounds = max(1, (int) $this->option('putaran'));
        $pause = max(0, (int) $this->option('jeda'));

        // Checked for BOTH modes: progress() reads comments_synced_at, so a
        // missing column breaks the very first line this command prints.
        $missing = SchemaGuard::missing(['account_media.comments_synced_at']);

        if ($missing !== []) {
            $this->newLine();
            $this->error(SchemaGuard::explain($missing));
            $this->newLine();

            return self::FAILURE;
        }

        $before = $sync->progress();

        // Answers "sudah sampai mana?" without making a single request. Asked
        // after every interruption, and the honest way to answer it used to be
        // to start a run and read the first line — which then went on to fetch
        // forty posts nobody asked for.
        if ($this->option('status')) {
            $this->newLine();
            $this->line('  <options=bold>POSISI SCRAPING KOMENTAR</>');
            $this->line(sprintf('    sudah diambil : %s postingan', number_format($before['done'])));
            $this->line(sprintf('    belum         : %s postingan', number_format($before['remaining'])));
            $this->line(sprintf('    total         : %s postingan', number_format($before['total'])));
            $this->newLine();
            $this->line($before['remaining'] > 0
                ? '    Lanjutkan: <options=bold>php artisan accounts:sync-comments --lanjut</>'
                : '    Semua postingan sudah pernah diambil komentarnya.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($backfill
            ? '  Mode: <options=bold>LANJUT</> — hanya postingan yang belum pernah diambil komentarnya'
            : '  Mode: <options=bold>SEGARKAN</> — postingan terbaru, untuk menangkap komentar baru');
        $this->line(sprintf(
            '  Sudah tercakup: %s / %s postingan (sisa %s)',
            number_format($before['done']),
            number_format($before['total']),
            number_format($before['remaining']),
        ));

        if ($backfill && $before['remaining'] === 0) {
            $this->newLine();
            $this->info('  Semua postingan sudah pernah diambil komentarnya — tidak ada yang tersisa.');
            $this->line('  Untuk menangkap komentar BARU di postingan lama, jalankan tanpa --lanjut.');
            $this->newLine();

            return self::SUCCESS;
        }

        $totals = ['posts' => 0, 'comments' => 0, 'failed' => 0];

        for ($round = 1; $round <= $rounds; $round++) {
            $result = $sync->syncAll($perRound, backfill: $backfill);

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }

            if ($rounds > 1) {
                $this->line(sprintf(
                    '    putaran %d/%d — %d postingan, %d komentar, %d gagal',
                    $round,
                    $rounds,
                    $result['posts'],
                    $result['comments'],
                    $result['failed'],
                ));
            }

            // Nothing left to take: further rounds would repeat an empty query.
            if ($backfill && $result['posts'] === 0) {
                break;
            }

            if ($pause > 0 && $round < $rounds) {
                sleep($pause);
            }
        }

        $after = $sync->progress();

        $this->newLine();
        $this->info(sprintf(
            '  Selesai — %d postingan diperiksa, %d komentar tersimpan, %d gagal.',
            $totals['posts'],
            $totals['comments'],
            $totals['failed'],
        ));

        $this->line(sprintf(
            '  Cakupan sekarang: %s / %s postingan (sisa %s)',
            number_format($after['done']),
            number_format($after['total']),
            number_format($after['remaining']),
        ));

        if ($after['remaining'] > 0) {
            // The whole point of the mode is that it can be run again, so say
            // so rather than leaving the reader to work out that it resumes.
            $this->line('  Lanjutkan: <options=bold>php artisan accounts:sync-comments --lanjut</>');
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
