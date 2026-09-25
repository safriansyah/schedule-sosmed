<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three additions from the refined data model.
 *
 * 1. `tickets.flag` — Netral | Lead. Orthogonal to status: status is where the
 *    ticket is in the queue, flag is whether there is a prospective student at
 *    the other end. Both are needed, and neither substitutes for the other.
 *
 * 2. `ticket_details` — the TiketDetail entity: the academic and address facts
 *    learned about a person during handling, keyed by (ticket, NIM) so one
 *    ticket can cover more than one student.
 *
 *    This is NOT a duplicate of `students`. A student row comes from the
 *    imported spreadsheet and is the institution's record; a ticket detail is
 *    what an operator established on this case, and it exists even when the
 *    NIM is not in any import yet. When the two do match, `student_id` links
 *    them and the detail is pre-filled from the student.
 *
 * 3. `pengirimpesanid` — the sender's platform id, on both the interaction and
 *    the ticket. Stored alongside the handle because handles get renamed and
 *    ids do not, so this is what actually identifies a commenter over time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('flag', 16)->default('netral')->after('priority')->index();

            // The commenter's stable id on the source platform.
            $table->string('source_sender_id')->nullable()->after('source_username')->index();
        });

        Schema::table('interactions', function (Blueprint $table) {
            $table->string('author_external_id')->nullable()->after('author_handle')->index();
        });

        Schema::create('ticket_details', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('nim', 32)->index();

            // Set when this NIM is also in the imported student list. Null is
            // normal: the operator may learn a NIM we have never imported.
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();

            $table->string('nama')->nullable();
            $table->string('fakultas')->nullable();
            $table->string('prodi')->nullable();

            // Four levels, as free text, for the same reason `students` stores
            // them that way: a region missing from the master list must never
            // stop an operator recording what they were told.
            $table->string('provinsi', 128)->nullable();
            $table->string('kabupaten', 128)->nullable()->index();
            $table->string('kecamatan', 128)->nullable();
            $table->string('kelurahan', 128)->nullable();
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();

            $table->string('no_hp', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('catatan')->nullable();

            // Anything else the operator recorded that has no column here.
            $table->json('extra')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The composite key from the model: one row per student per ticket.
            $table->unique(['ticket_id', 'nim']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_details');

        Schema::table('interactions', function (Blueprint $table) {
            $table->dropColumn('author_external_id');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['flag', 'source_sender_id']);
        });
    }
};
