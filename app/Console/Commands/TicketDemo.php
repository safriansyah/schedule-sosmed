<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\Ticket;
use Database\Seeders\TicketDemoSeeder;
use Illuminate\Console\Command;

/**
 * Add or remove the ticket list's three examples.
 *
 * Same bargain as interactions:demo — demo data that cannot be removed
 * cleanly ends up mixed into real reports and nobody dares delete it. Every
 * example carries `extra.demo`, so `--clear` takes exactly those and nothing
 * else, and force-deletes them so they do not linger as soft-deleted rows
 * holding on to their ticket numbers.
 */
class TicketDemo extends Command
{
    protected $signature = 'tickets:demo {--clear : Hapus semua contoh tiket}';

    protected $description = 'Isi daftar tiket dengan 3 contoh (atau bersihkan kembali)';

    public function handle(): int
    {
        $query = Ticket::withTrashed()->where('extra->demo', true);

        if ($this->option('clear')) {
            $count = (clone $query)->count();

            $query->forceDelete();

            // The fictional student the second example hangs on goes too,
            // otherwise it sits in the student list looking like a real
            // person nobody has assigned yet.
            $students = Student::withTrashed()->where('nim', TicketDemoSeeder::DEMO_NIM)->forceDelete();

            $this->info("Dihapus: {$count} contoh tiket".($students ? ', 1 mahasiswa contoh' : '').'.');

            return self::SUCCESS;
        }

        if (($existing = (clone $query)->count()) > 0) {
            $this->warn("  Sudah ada {$existing} contoh tiket. Bersihkan dulu dengan --clear bila ingin dibuat ulang.");

            return self::SUCCESS;
        }

        $this->call('db:seed', ['--class' => TicketDemoSeeder::class, '--force' => true]);

        $this->newLine();
        $this->line('  Buka <options=bold>/tickets</> — tiga contoh: dari komentar, dari data mahasiswa, dan yang sudah ditutup.');
        $this->line('  Hapus lagi dengan: <options=bold>php artisan tickets:demo --clear</>');

        return self::SUCCESS;
    }
}
