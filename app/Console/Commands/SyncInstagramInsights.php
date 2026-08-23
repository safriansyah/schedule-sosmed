<?php

namespace App\Console\Commands;

use App\Services\Publishing\InstagramInsightsSync;
use Illuminate\Console\Command;

class SyncInstagramInsights extends Command
{
    protected $signature = 'accounts:sync-insights';

    protected $description = 'Tarik postingan Instagram beserta insight-nya dan simpan snapshot harian.';

    public function handle(InstagramInsightsSync $sync): int
    {
        $result = $sync->syncAll();

        $this->info(sprintf(
            'Selesai — %d postingan diperiksa, %d snapshot tersimpan, %d akun gagal.',
            $result['media'],
            $result['snapshots'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
