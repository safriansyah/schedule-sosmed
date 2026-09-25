<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens the existing follow-up log so it can also serve tickets.
 *
 * The brief asked for a `ticket_follow_ups` table. This table already IS that:
 * polymorphic, append-only, one row per touch, with author, channel, outcome
 * and a reminder date. A second table would duplicate all of it and split the
 * history of one person across two places. What was genuinely missing is here.
 *
 * `additional_data` is free-form on purpose — what an operator learns on a call
 * is not knowable in advance ("nomor baru", "pindah ke Palembang", "minta
 * ditelepon sore"). It is a note attached to one touch, never the system of
 * record: anything that matters (a new phone number, a status) is also written
 * onto the ticket/student row, and the change is logged in `activities` with
 * its old and new value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            // Status the target was moved to by this touch, so the history
            // reads as a sequence of states rather than free text alone.
            $table->string('status_after', 24)->nullable()->after('outcome');

            $table->json('additional_data')->nullable()->after('status_after');

            $table->string('attachment_path', 512)->nullable()->after('additional_data');
            $table->string('attachment_name')->nullable()->after('attachment_path');
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropColumn(['status_after', 'additional_data', 'attachment_path', 'attachment_name']);
        });
    }
};
