<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which worksheet an import read.
 *
 * The real file has two tabs — a six-cell DASHBOARD and the 7.391-row
 * DATA INDUK — so "the spreadsheet" is not enough to identify the data. The
 * admin picks the tab at preview time and it is recorded here, both to re-read
 * the same rows on confirm and to answer "where did this come from" later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_imports', function (Blueprint $table) {
            $table->string('sheet')->nullable()->after('extension');
        });
    }

    public function down(): void
    {
        Schema::table('student_imports', function (Blueprint $table) {
            $table->dropColumn('sheet');
        });
    }
};
