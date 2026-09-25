<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Interaction;
use Database\Seeders\CrmExampleSeeder;
use Illuminate\Console\Command;

/**
 * Adds (or removes) a worked set of CRM examples.
 *
 * Kept as a command rather than pasted-in rows so it can be undone cleanly:
 * every example is tagged, and --clear removes exactly those and nothing else.
 */
class CrmExamples extends Command
{
    protected $signature = 'crm:examples
                            {--clear : Hapus semua data contoh}
                            {--classify : Langsung nilai dengan AI, jangan tunggu penjadwal}';

    protected $description = 'Isi CRM dengan contoh agent & interaksi untuk mencoba fitur';

    public function handle(): int
    {
        if ($this->option('clear')) {
            return $this->clear();
        }

        $this->info('Membuat contoh agent & interaksi…');
        $this->call('db:seed', ['--class' => CrmExampleSeeder::class, '--force' => true]);

        if ($this->option('classify')) {
            $this->newLine();
            $this->line('  Menilai dengan AI…');
            $this->call('interactions:classify');
        } else {
            $this->newLine();
            $this->line('  <fg=gray>Interaksi sengaja dibiarkan belum dinilai — penjadwal akan');
            $this->line('  mengambilnya dalam 15 menit. Pakai --classify untuk langsung.</>');
        }

        $this->summary();

        return self::SUCCESS;
    }

    private function clear(): int
    {
        if (! $this->option('no-interaction')
            && ! $this->confirm('Hapus semua data contoh (agent & interaksi bertanda [contoh])?', false)) {
            $this->line('Dibatalkan.');

            return self::SUCCESS;
        }

        $removed = CrmExampleSeeder::clear();

        $this->info("Dihapus: {$removed['contacts']} kontak, {$removed['interactions']} interaksi.");

        return self::SUCCESS;
    }

    private function summary(): void
    {
        $this->newLine();

        $agents = Contact::agents()->with('region')->get();

        if ($agents->isNotEmpty()) {
            $this->table(
                ['Kode', 'Nama', 'WhatsApp', 'Wilayah', 'Akun'],
                $agents->map(fn (Contact $c) => [
                    $c->agent_code,
                    $c->name(),
                    \App\Support\PhoneNumber::pretty($c->phone_e164) ?? '—',
                    str($c->region?->label() ?? '—')->limit(34),
                    $c->identities->map->display()->implode(', '),
                ])->all(),
            );
        }

        $this->line(sprintf(
            '  Total: %d kontak, %d agent, %d interaksi (%d belum dinilai)',
            Contact::canonical()->count(),
            Contact::agents()->count(),
            Interaction::count(),
            Interaction::whereNull('ai_classified_at')->count(),
        ));
    }
}
