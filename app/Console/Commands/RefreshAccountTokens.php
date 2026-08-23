<?php

namespace App\Console\Commands;

use App\Services\Publishing\TokenRefresher;
use Illuminate\Console\Command;

class RefreshAccountTokens extends Command
{
    protected $signature = 'accounts:refresh-tokens {--force : Perbarui semua token tanpa menunggu mendekati kedaluwarsa}';

    protected $description = 'Perpanjang masa berlaku token akun sosmed sebelum kedaluwarsa.';

    public function handle(TokenRefresher $refresher): int
    {
        $result = $refresher->refreshAll($this->option('force'));

        $this->info(sprintf(
            'Selesai — diperbarui: %d, dilewati: %d, gagal: %d.',
            $result['refreshed'],
            $result['skipped'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
