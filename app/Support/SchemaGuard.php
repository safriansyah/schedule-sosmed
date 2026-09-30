<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Fails a command early, and in words, when the database is behind the code.
 *
 * This exists because of a specific, repeatable accident: the app is moved
 * between machines by copying folders, and `database/` is easy to leave out
 * when `app/` is what obviously changed. The code then runs against a schema
 * that has no idea about the new columns.
 *
 * What made it worth a guard rather than a comment is WHERE it failed. The
 * backfill READS `media_cursor` before it writes it, and Eloquent returns null
 * for a column that does not exist rather than complaining — so a run happily
 * made several hundred Instagram API calls, then died on the save with
 * "Unknown column 'media_cursor' in 'field list'". The quota was spent, the
 * message named a column rather than a missing migration, and nothing pointed
 * at the fix.
 *
 * Checked before the first API call, so nothing is wasted.
 */
class SchemaGuard
{
    /**
     * Which of these `table.column` pairs are missing.
     *
     * @param  array<int, string>  $columns  e.g. ['social_accounts.media_cursor']
     * @return array<int, string>
     */
    public static function missing(array $columns): array
    {
        $absent = [];

        foreach ($columns as $pair) {
            [$table, $column] = explode('.', $pair, 2);

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                $absent[] = $pair;
            }
        }

        return $absent;
    }

    /**
     * The message to print when something is missing. One sentence of cause,
     * then the command that fixes it — the reader is at a terminal on another
     * machine, not reading this file.
     *
     * @param  array<int, string>  $missing
     */
    public static function explain(array $missing): string
    {
        return "  Database belum diperbarui — kolom berikut belum ada:\n"
            .'    '.implode("\n    ", $missing)."\n\n"
            ."  Ini terjadi kalau folder database/ tidak ikut disalin saat pindah komputer.\n"
            ."  Perbaikan:\n"
            ."    1. Salin folder database/ (terutama database/migrations/)\n"
            ."    2. php artisan migrate --force\n"
            .'    3. php artisan config:clear';
    }
}
