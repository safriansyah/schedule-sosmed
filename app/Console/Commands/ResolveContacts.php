<?php

namespace App\Console\Commands;

use App\Models\Interaction;
use App\Services\Crm\ContactResolver;
use Illuminate\Console\Command;
use Throwable;

/**
 * Attaches a contact to every interaction that does not have one.
 *
 * Needed once after the backfill migration (which copies comments across
 * without resolving them, to keep the migration fast and side-effect free),
 * and useful afterwards whenever a sync ran while something was broken.
 */
class ResolveContacts extends Command
{
    protected $signature = 'contacts:resolve {--limit=0 : Batasi jumlah baris, 0 = semua}';

    protected $description = 'Cocokkan interaksi tanpa kontak ke database kontak (UID)';

    public function handle(ContactResolver $resolver): int
    {
        $query = Interaction::whereNull('contact_id')->whereNotNull('author_handle');

        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Semua interaksi sudah punya kontak.');

            return self::SUCCESS;
        }

        $this->info("Mencocokkan {$total} interaksi…");
        $bar = $this->output->createProgressBar($total);

        $resolved = 0;
        $failed = 0;

        // chunkById, not chunk: the query filters on the column being written,
        // so an offset-based chunk would skip rows as the result set shrinks.
        $query->chunkById(200, function ($interactions) use ($resolver, $bar, &$resolved, &$failed) {
            foreach ($interactions as $interaction) {
                try {
                    $resolver->resolveFor($interaction) ? $resolved++ : $failed++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn("Gagal untuk {$interaction->author_handle}: ".$e->getMessage());
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Selesai — {$resolved} tercocokkan, {$failed} gagal.");

        return self::SUCCESS;
    }
}
