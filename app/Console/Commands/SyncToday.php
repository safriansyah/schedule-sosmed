<?php

namespace App\Console\Commands;

use App\Services\Publishing\QuickSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * The command equivalent of the "Sinkron sekarang" button on /monitoring.
 *
 * The button had no counterpart on the command line, so there was no way to do
 * from a terminal — or from the scheduler — exactly what the button does. Both
 * now go through QuickSync, so they cannot drift apart.
 */
class SyncToday extends Command
{
    protected $signature = 'accounts:sync-today';

    protected $description = 'Sinkron cepat hari ini (sama persis dengan tombol Sinkron di halaman Monitoring).';

    public function handle(QuickSync $sync): int
    {
        $this->newLine();
        $this->line(sprintf(
            '  Cakupan: %d halaman terbaru (± %d postingan) + komentar %d postingan terbaru.',
            QuickSync::PAGES,
            QuickSync::PAGES * 50,
            QuickSync::COMMENT_POSTS,
        ));
        $this->line('  Ini sinkron RINGAN, bukan tarik ulang seluruh riwayat.');
        $this->newLine();

        try {
            $result = $sync->run();
        } catch (Throwable $e) {
            $this->error('  Sinkron gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('  '.$sync->summary($result));

        $this->newLine();
        $this->line('  Untuk menarik riwayat lama, gunakan mode lanjut:');
        $this->line('    <options=bold>php artisan accounts:sync-insights --lanjut</>');
        $this->line('    <options=bold>php artisan accounts:sync-comments --lanjut</>');
        $this->newLine();

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
