<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attachment on a ticket — the "Attachment jika diperlukan" of the brief.
 *
 * Single, not a table of many, to match how follow-ups already work: the
 * evidence that belongs to a particular touch (a payment screenshot, a photo
 * of a form) is attached to THAT follow-up, and the ticket-level slot is for
 * the one document the case opened with. A many-attachments table can be added
 * later without changing either.
 *
 * The file itself lives on the `public` disk; only its path and the name the
 * uploader saw are stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('attachment_path', 512)->nullable()->after('resolution_note');
            $table->string('attachment_name')->nullable()->after('attachment_path');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name']);
        });
    }
};
