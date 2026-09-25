<?php

namespace App\Console\Commands;

use App\Enums\RegionLevel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads the full Indonesian region tree from a CSV.
 *
 * Expects the standard two-column "kode,nama" format published by BPS and
 * mirrored in several open datasets:
 *
 *     11,ACEH
 *     11.01,KABUPATEN ACEH SELATAN
 *     11.01.01,Bakongan
 *     11.01.01.2001,Keude Bakongan
 *
 * The level is derived from the number of dots, and the parent from the code
 * with its last segment removed — so the file can be in any order as long as
 * parents appear before children, which the published files already are.
 *
 * ~90,000 rows, so inserts are batched and the whole thing runs in one
 * transaction: a half-loaded region tree is worse than none.
 */
class ImportRegions extends Command
{
    protected $signature = 'regions:import
                            {file : Path to the wilayah CSV (kode,nama)}
                            {--fresh : Kosongkan tabel regions dulu}';

    protected $description = 'Impor data wilayah Indonesia (provinsi → desa) dari file CSV';

    private const BATCH = 1000;

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_readable($path)) {
            $this->error("File tidak ditemukan atau tidak bisa dibaca: {$path}");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            if (! $this->confirm('Hapus semua data wilayah yang ada sekarang?', false)) {
                return self::FAILURE;
            }

            // Contacts reference regions with nullOnDelete, so this loses the
            // wilayah on existing contacts. Deliberately behind a confirmation.
            DB::table('regions')->delete();
        }

        $handle = fopen($path, 'r');
        $now = now();

        /** @var array<string, string> $names  code => full path, for building children */
        $names = DB::table('regions')->pluck('full_path', 'code')->all();

        $buffer = [];
        $imported = 0;
        $skipped = 0;

        $this->info('Mengimpor wilayah…');
        $bar = $this->output->createProgressBar();

        DB::beginTransaction();

        try {
            while (($row = fgetcsv($handle)) !== false) {
                [$code, $name] = array_pad($row, 2, null);

                $code = trim((string) $code);
                $name = $this->titleCase(trim((string) $name));

                if ($code === '' || $name === '' || ! preg_match('/^\d+(\.\d+)*$/', $code)) {
                    $skipped++;

                    continue;
                }

                $level = RegionLevel::fromCode($code);
                $parentCode = str_contains($code, '.') ? substr($code, 0, strrpos($code, '.')) : null;

                $parentPath = $parentCode !== null ? ($names[$parentCode] ?? null) : null;
                $fullPath = $parentPath ? "{$name}, {$parentPath}" : $name;

                $names[$code] = $fullPath;

                $buffer[] = [
                    'code' => $code,
                    // parent_id is filled by linkParents() once every row
                    // exists — a child and its parent are usually in the same
                    // batch, so the parent has no id yet at this point.
                    'parent_id' => null,
                    'level' => $level->value,
                    'name' => $name,
                    'full_path' => mb_substr($fullPath, 0, 512),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($buffer) >= self::BATCH) {
                    $imported += $this->flush($buffer);
                    $bar->advance(self::BATCH);
                }
            }

            $imported += $this->flush($buffer);

            $linked = $this->linkParents();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);
            $this->newLine(2);
            $this->error('Impor dibatalkan, tidak ada perubahan: '.$e->getMessage());

            return self::FAILURE;
        }

        fclose($handle);
        $bar->finish();
        $this->newLine(2);

        $this->info("Selesai — {$imported} wilayah diimpor, {$linked} tersambung ke induknya, {$skipped} baris dilewati.");

        foreach (RegionLevel::cases() as $level) {
            $count = DB::table('regions')->where('level', $level->value)->count();
            $this->line(sprintf('  %-18s %s', $level->label(), number_format($count)));
        }

        return self::SUCCESS;
    }

    /**
     * Write one batch. Cleared by reference so the caller's buffer resets too.
     *
     * `parent_id` is excluded from the update columns: linkParents() owns it,
     * and letting the upsert null it on a re-import would orphan the tree.
     *
     * @param  array<int, array<string, mixed>>  $buffer
     */
    private function flush(array &$buffer): int
    {
        if ($buffer === []) {
            return 0;
        }

        DB::table('regions')->upsert($buffer, ['code'], ['level', 'name', 'full_path', 'updated_at']);

        $written = count($buffer);
        $buffer = [];

        return $written;
    }

    /**
     * Second pass: attach every region to its parent.
     *
     * Runs after all rows exist, so file order does not matter. The code → id
     * map is one row per region (~90k ints), which is far cheaper than a
     * lookup query per row.
     */
    private function linkParents(): int
    {
        $ids = DB::table('regions')->pluck('id', 'code')->all();
        $linked = 0;
        $updates = [];

        foreach (DB::table('regions')->select('id', 'code', 'parent_id')->cursor() as $region) {
            if (! str_contains($region->code, '.')) {
                continue;
            }

            $parentCode = substr($region->code, 0, strrpos($region->code, '.'));
            $parentId = $ids[$parentCode] ?? null;

            if ($parentId === null || $parentId === $region->parent_id) {
                continue;
            }

            $updates[$parentId][] = $region->id;
            $linked++;
        }

        // Grouped by parent, so a kecamatan with 30 villages is one statement.
        foreach ($updates as $parentId => $childIds) {
            foreach (array_chunk($childIds, 500) as $chunk) {
                DB::table('regions')->whereIn('id', $chunk)->update(['parent_id' => $parentId]);
            }
        }

        return $linked;
    }

    /** The published files are ALL CAPS for provinces and regencies; soften them. */
    private function titleCase(string $name): string
    {
        if ($name !== mb_strtoupper($name)) {
            return $name;
        }

        $titled = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE, 'UTF-8');

        // Keep the administrative prefixes and well-known initialisms upper.
        return preg_replace_callback(
            '/\b(dki|di|kab|kota|dan|nusa)\b/iu',
            fn ($m) => in_array(mb_strtolower($m[1]), ['dki', 'di'], true)
                ? mb_strtoupper($m[1])
                : $m[0],
            $titled,
        ) ?? $titled;
    }
}
