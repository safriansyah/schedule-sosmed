<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Empty the operational data, keep the setup.
 *
 * For handing a machine over: the accounts, roles, permissions, reference
 * lists and site settings stay; everything the app produced while it was being
 * tried out goes — students, tickets, comments, scraped posts, activity log,
 * and the social account rows that hold the access tokens.
 *
 * The keep list is EXPLICIT and everything else is emptied, deliberately the
 * opposite way round from a delete list. A table added next month is then
 * cleared by default rather than quietly surviving with stale rows; the price
 * is that a new REFERENCE table has to be added to KEEP, which the command
 * makes obvious by printing everything it is about to touch.
 *
 * TRUNCATE is DDL: it commits itself and cannot be rolled back. Hence the
 * dry run, the confirmation, and the count printed next to every table.
 */
class DataReset extends Command
{
    protected $signature = 'data:reset
                            {--dry-run : Tampilkan apa yang akan dikosongkan, tanpa menghapus}
                            {--force : Jangan tanya konfirmasi}';

    protected $description = 'Kosongkan data operasional, simpan akun/role/pengaturan/data acuan';

    /**
     * Tables that survive, and why. Anything not listed here is emptied.
     *
     * @var array<string, string>
     */
    private const KEEP = [
        'migrations' => 'versi skema — menghapusnya membuat Laravel mengira database masih kosong',
        'users' => 'akun pengguna',
        'roles' => 'daftar role',
        'permissions' => 'daftar izin',
        'permission_role' => 'izin per role',
        'settings' => 'identitas website (nama, logo, favicon) & format ID tiket',
        'regions' => 'data acuan wilayah (hasil seeder)',
        'ticket_categories' => 'kategori & sub kategori tiket (hasil seeder)',
    ];

    /** Emptied, and worth saying out loud because someone always asks. */
    private const NOTABLE = [
        'social_accounts' => 'TOKEN Instagram ikut terhapus — memang ini yang diinginkan saat pindah akun',
        'sessions' => 'semua orang harus login ulang',
        'activities' => 'riwayat audit dari masa uji coba',
        'cache' => 'aman: isinya dibangun ulang sendiri',
    ];

    public function handle(): int
    {
        // Scoped to THIS database, and base tables only.
        //
        // Schema::getTableListing() hands back tables from every schema the
        // connection user can see, so on a shared MySQL it happily returned
        // another application's tables — and this command would then have
        // tried to truncate them.
        $all = collect(DB::select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES '
            ."WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
        ))->pluck('name')->values();

        $keep = $all->filter(fn ($t) => array_key_exists($t, self::KEEP));
        $empty = $all->reject(fn ($t) => array_key_exists($t, self::KEEP))->values();

        $missing = array_diff(array_keys(self::KEEP), $all->all());

        if ($missing !== []) {
            // A rename or a dropped table would otherwise mean something meant
            // to be kept gets emptied without anyone noticing.
            $this->error('Tabel berikut ada di daftar SIMPAN tapi tidak ada di database: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $counts = [];
        $total = 0;

        foreach ($empty as $table) {
            $counts[$table] = DB::table($table)->count();
            $total += $counts[$table];
        }

        $this->newLine();
        $this->line('  <options=bold>DISIMPAN</> ('.$keep->count().' tabel)');

        foreach ($keep as $table) {
            $this->line(sprintf('    %-22s %8s   %s', $table, number_format(DB::table($table)->count()), self::KEEP[$table]));
        }

        $this->newLine();
        $this->line('  <options=bold>DIKOSONGKAN</> ('.$empty->count().' tabel, '.number_format($total).' baris)');

        foreach ($empty as $table) {
            $note = self::NOTABLE[$table] ?? '';
            $this->line(sprintf('    %-22s %8s   %s', $table, number_format($counts[$table]), $note));
        }

        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('  --dry-run: tidak ada yang dihapus.');

            return self::SUCCESS;
        }

        $this->error('  TRUNCATE tidak bisa dibatalkan. Pastikan sudah ada backup kalau datanya masih dibutuhkan.');

        if (! $this->option('force') && ! $this->confirm("Kosongkan {$empty->count()} tabel ({$total} baris)?", false)) {
            $this->line('  Dibatalkan.');

            return self::SUCCESS;
        }

        // Foreign keys are switched off for the duration rather than working
        // out a safe order: the whole dependent set is emptied in one go, so
        // there is no moment where a surviving row points at a deleted one.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($empty as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->newLine();
        $this->info("  Selesai. {$empty->count()} tabel dikosongkan, {$keep->count()} tabel disimpan.");
        $this->line('  Login ulang diperlukan (tabel sessions ikut dikosongkan).');
        $this->newLine();
        $this->line('  Langkah berikutnya:');
        $this->line('    1. Sambungkan akun Instagram yang baru lewat menu Akun Sosmed');
        $this->line('    2. Import data mahasiswa lewat menu Import Data');
        $this->line('    3. Periksa ulang: <options=bold>php artisan data:reset --dry-run</>');

        return self::SUCCESS;
    }
}
