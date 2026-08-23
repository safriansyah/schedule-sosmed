<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which stage a revision was raised from, so that when the creative
 * resubmits, the content returns to that stage instead of always re-entering
 * the approval queue. A revision raised during verification skips a redundant
 * re-approval — the curator already signed off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revisions', function (Blueprint $table) {
            // 'approval' | 'verification'. Null = legacy rows, treated as approval.
            $table->string('return_to')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('revisions', function (Blueprint $table) {
            $table->dropColumn('return_to');
        });
    }
};
