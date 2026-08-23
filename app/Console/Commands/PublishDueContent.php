<?php

namespace App\Console\Commands;

use App\Services\Publishing\PublishingService;
use Illuminate\Console\Command;

class PublishDueContent extends Command
{
    protected $signature = 'content:publish-due';

    protected $description = 'Terbitkan semua konten terjadwal yang sudah tiba waktunya.';

    public function handle(PublishingService $publishing): int
    {
        $result = $publishing->publishDue();

        if ($result['attempted'] === 0) {
            $this->info('Tidak ada konten yang perlu diterbitkan.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Diproses: %d — berhasil: %d, gagal: %d.',
            $result['attempted'],
            $result['published'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
