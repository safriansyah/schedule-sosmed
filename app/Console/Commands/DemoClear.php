<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Interaction;
use App\Models\Student;
use App\Models\Task;
use App\Models\Ticket;
use Database\Seeders\CrmExampleSeeder;
use Database\Seeders\InteractionDemoSeeder;
use Database\Seeders\TicketDemoSeeder;
use Illuminate\Console\Command;

/**
 * Remove every piece of example data, in one command.
 *
 * The examples arrived in four batches, each with its own `--clear`, and by
 * the time someone is deploying to a real server they should not have to
 * remember all four or know which is which. Getting this wrong in either
 * direction is expensive: leaving demo rows in makes the first reports wrong,
 * and a blunt "delete everything" would take the institution's real data with
 * it.
 *
 * So every set is matched by the marker its seeder wrote — a NIM, an
 * `external_id` prefix, a `[contoh]` tag, an `extra.demo` flag — never by
 * "recently created" or "looks like test data". Real rows carry none of those
 * markers and cannot be caught.
 *
 * The one set with no marker of its own is the four seeded tasks, matched by
 * their exact titles; a task somebody renamed is therefore left alone, which
 * is the safe direction to be wrong in.
 */
class DemoClear extends Command
{
    protected $signature = 'demo:clear
                            {--dry-run : Tampilkan apa yang akan dihapus, tanpa menghapus}
                            {--force : Jangan tanya konfirmasi}';

    protected $description = 'Hapus seluruh data contoh (CRM, inbox, tiket, task, mahasiswa demo)';

    /** Titles TaskSeeder writes. Matched exactly — a renamed task is not ours. */
    private const SEEDED_TASKS = [
        'Sosialisasi Registrasi Mahasiswa',
        'Follow Up Mahasiswa Semester 2026.1',
        'Monitoring Komentar Instagram',
        'Laporan Mingguan',
    ];

    public function handle(): int
    {
        $found = $this->survey();
        $total = array_sum(array_column($found, 'count'));

        $this->newLine();
        $this->line('  <options=bold>Data contoh yang ditemukan</>');

        foreach ($found as $row) {
            // mb-aware padding: the labels contain "…", which sprintf's %-42s
            // counts as three bytes and misaligns the column.
            $this->line('    '.$row['label'].str_repeat(' ', max(1, 44 - mb_strlen($row['label']))).number_format($row['count']));
        }

        $this->newLine();

        if ($total === 0) {
            $this->info('  Tidak ada data contoh. Tidak ada yang dihapus.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('  --dry-run: tidak ada yang dihapus.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Hapus {$total} baris data contoh?", false)) {
            $this->line('  Dibatalkan.');

            return self::SUCCESS;
        }

        // Each set keeps its own command: they already know the order their
        // own rows have to go in (tickets born from a demo comment before the
        // comment itself, for one).
        $this->call('tickets:demo', ['--clear' => true]);
        $this->call('interactions:demo', ['--clear' => true]);
        // crm:examples asks for its own confirmation. The admin has already
        // answered one here, and being asked twice for the same decision is
        // how people start typing "yes" without reading.
        $this->call('crm:examples', ['--clear' => true, '--no-interaction' => true]);

        $tasks = Task::whereIn('title', self::SEEDED_TASKS)->get();

        foreach ($tasks as $task) {
            $task->checks()->delete();
            $task->delete();
        }

        if ($tasks->isNotEmpty()) {
            $this->info("Dihapus: {$tasks->count()} task contoh.");
        }

        // StudentSeeder's placeholder rows. The real import never uses these
        // NIMs — they are the ten made-up ones from before the file arrived.
        $students = Student::whereIn('nim', $this->seededNims())->delete();

        if ($students > 0) {
            $this->info("Dihapus: {$students} mahasiswa contoh.");
        }

        $this->newLine();
        $this->line('  <options=bold>Selesai.</> Periksa ulang dengan: <options=bold>php artisan demo:clear --dry-run</>');

        return self::SUCCESS;
    }

    /** @return array<int, array{label: string, count: int}> */
    private function survey(): array
    {
        return [
            [
                'label' => 'Tiket contoh (extra.demo)',
                'count' => Ticket::withTrashed()->where('extra->demo', true)->count(),
            ],
            [
                'label' => 'Mahasiswa contoh '.TicketDemoSeeder::DEMO_NIM,
                'count' => Student::withTrashed()->where('nim', TicketDemoSeeder::DEMO_NIM)->count(),
            ],
            [
                'label' => 'Interaksi inbox ('.InteractionDemoSeeder::PREFIX.'…)',
                'count' => Interaction::where('external_id', 'like', InteractionDemoSeeder::PREFIX.'%')->count(),
            ],
            [
                'label' => 'Interaksi CRM (contoh-…)',
                'count' => Interaction::where('external_id', 'like', 'contoh-%')->count(),
            ],
            [
                'label' => 'Kontak CRM '.CrmExampleSeeder::TAG,
                'count' => Contact::where('notes', 'like', '%'.CrmExampleSeeder::TAG.'%')->count(),
            ],
            [
                'label' => 'Task contoh (judul bawaan seeder)',
                'count' => Task::whereIn('title', self::SEEDED_TASKS)->count(),
            ],
            [
                'label' => 'Mahasiswa contoh StudentSeeder',
                'count' => Student::whereIn('nim', $this->seededNims())->count(),
            ],
        ];
    }

    /** @return array<int, string> */
    private function seededNims(): array
    {
        // Read from the seeder rather than copied, so the two cannot drift.
        $reflection = new \ReflectionClass(\Database\Seeders\StudentSeeder::class);

        if (! $reflection->hasConstant('DEMO')) {
            return [];
        }

        return array_column((array) $reflection->getConstant('DEMO'), 0);
    }
}
