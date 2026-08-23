<?php

namespace App\Console\Commands;

use App\Services\Publishing\AccountMetricsSync;
use Illuminate\Console\Command;

class SyncAccountMetrics extends Command
{
    protected $signature = 'accounts:sync-metrics';

    protected $description = 'Ambil snapshot harian metrik setiap akun sosmed terhubung.';

    public function handle(AccountMetricsSync $sync): int
    {
        $result = $sync->syncAll();

        $this->info("Sinkron selesai — berhasil: {$result['synced']}, gagal: {$result['failed']}.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
