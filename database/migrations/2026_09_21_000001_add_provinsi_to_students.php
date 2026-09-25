<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give students the top of the region hierarchy.
 *
 * Assignment is now done by region rather than by headcount, and the admin
 * picks Provinsi → Kabupaten → Kecamatan → Kelurahan. Three of those already
 * existed; `provinsi` did not, because the institution's export has no such
 * column (its rows are almost all Bangka Belitung, so the province was
 * implied). The column is added now so the moment a file carrying it arrives
 * the top level fills itself in — see `config/students.php`.
 *
 * The composite index matches how the region assignment actually queries:
 * narrowing left to right, always over the unassigned pool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('provinsi', 64)->nullable()->after('region_id')->index();

            $table->index(
                ['assigned_to', 'provinsi', 'kabupaten', 'kecamatan', 'kelurahan'],
                'students_region_handout_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('students_region_handout_index');
            $table->dropIndex(['provinsi']);
            $table->dropColumn('provinsi');
        });
    }
};
