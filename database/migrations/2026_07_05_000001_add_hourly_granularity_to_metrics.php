<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow more than one snapshot per day.
 *
 * `captured_on` stays as the daily rollup key used by the period comparisons;
 * `captured_at` records the exact moment so hourly growth can be derived when
 * the sync runs more often than once a day.
 *
 * Written to be re-runnable: each step checks the current schema first.
 */
return new class extends Migration
{
    private const TABLES = [
        'media_metrics' => 'account_media_id',
        'account_metrics' => 'social_account_id',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $ownerColumn) {
            if (! Schema::hasColumn($table, 'captured_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->timestamp('captured_at')->nullable()->after('captured_on')->index();
                });
            }

            // Existing rows predate the column — anchor them to their insert time.
            DB::table($table)->whereNull('captured_at')->update([
                'captured_at' => DB::raw('created_at'),
            ]);

            $dailyKey = "{$table}_{$ownerColumn}_captured_on_unique";
            $hourlyKey = "{$table}_{$ownerColumn}_captured_at_unique";

            // Create the replacement first: the owner column carries a foreign
            // key, and MySQL refuses to drop the only index covering it.
            if (! $this->hasIndex($table, $hourlyKey)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint
                    ->unique([$ownerColumn, 'captured_at'], $hourlyKey));
            }

            if ($this->hasIndex($table, $dailyKey)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($dailyKey));
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $ownerColumn) {
            $hourlyKey = "{$table}_{$ownerColumn}_captured_at_unique";

            if ($this->hasIndex($table, $hourlyKey)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($hourlyKey));
            }

            Schema::table($table, function (Blueprint $blueprint) use ($ownerColumn) {
                $blueprint->unique([$ownerColumn, 'captured_on']);
                $blueprint->dropColumn('captured_at');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]) !== [];
    }
};
