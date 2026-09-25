<?php

namespace App\Console\Commands;

use App\Models\Interaction;
use Database\Seeders\InteractionDemoSeeder;
use Illuminate\Console\Command;

/**
 * Add or remove the inbox's example comments.
 *
 * Demo data that cannot be removed cleanly is worse than none — it ends up
 * mixed into real reports and nobody dares delete it. Every example row is
 * keyed `DEMO-…`, so `--clear` takes exactly those and nothing else.
 */
class InteractionDemo extends Command
{
    protected $signature = 'interactions:demo {--clear : Hapus semua contoh interaksi}';

    protected $description = 'Isi inbox dengan contoh interaksi (atau bersihkan kembali)';

    public function handle(): int
    {
        $query = Interaction::where('external_id', 'like', InteractionDemoSeeder::PREFIX.'%');

        if ($this->option('clear')) {
            $count = (clone $query)->count();

            // Tickets raised from a demo comment go too, otherwise the ticket
            // list keeps a row pointing at a comment that no longer exists.
            $tickets = \App\Models\Ticket::whereIn('interaction_id', (clone $query)->pluck('id'))->forceDelete();

            $query->delete();

            $this->info("Dihapus: {$count} contoh interaksi".($tickets ? ", {$tickets} tiket terkait" : '').'.');

            return self::SUCCESS;
        }

        $this->call('db:seed', ['--class' => InteractionDemoSeeder::class, '--force' => true]);

        $this->newLine();
        $this->line('  Buka <options=bold>/interactions?tab=mine</> sebagai Operator Satu atau PIC Satu.');
        $this->line('  Hapus lagi dengan: <options=bold>php artisan interactions:demo --clear</>');

        return self::SUCCESS;
    }
}
