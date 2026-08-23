<?php

namespace App\Console\Commands;

use App\Services\Publishing\InstagramCommentSync;
use Illuminate\Console\Command;

class SyncInstagramComments extends Command
{
    protected $signature = 'accounts:sync-comments';

    protected $description = 'Tarik komentar publik dari postingan (via comment viewer) dan simpan.';

    public function handle(InstagramCommentSync $sync): int
    {
        $result = $sync->syncAll();

        $this->info(sprintf(
            'Selesai — %d postingan diperiksa, %d komentar tersimpan, %d gagal.',
            $result['posts'],
            $result['comments'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
