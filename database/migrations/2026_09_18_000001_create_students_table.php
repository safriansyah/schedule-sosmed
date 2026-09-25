<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Returning students flagged as probably not continuing this semester.
 *
 * THE REAL SPREADSHEET IS NOT IN HAND YET. Two consequences are designed in
 * here on purpose:
 *
 *  1. Only `nim` is structurally required. Every other column is nullable, so a
 *     column the real file turns out not to have costs a mapping change, not a
 *     migration.
 *  2. `extra` holds every source column we did not recognise, verbatim. Nothing
 *     from the file is ever silently dropped, so when the mapping is corrected
 *     the data is still there to re-read.
 *
 * Region is stored BOTH ways: free text exactly as the file spelled it, and an
 * optional FK into `regions` when the name resolves. Filters read the text, so
 * a kecamatan missing from the region master never hides a student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();

            // Identity. NIM is the natural key — an import re-run updates the
            // existing row rather than creating a twin.
            $table->string('nim', 32)->unique();
            $table->string('nac', 64)->nullable()->index();
            $table->string('nama')->nullable();
            $table->string('email')->nullable();
            // E.164 without the plus, matching contacts.phone_e164, so the two
            // can be matched on the number later.
            $table->string('no_hp', 32)->nullable()->index();
            $table->string('no_hp_raw', 64)->nullable();   // as typed in the file

            $table->string('program_studi')->nullable();
            $table->string('fakultas')->nullable();
            $table->string('semester_terakhir', 32)->nullable()->index();

            // Free text from the file. Indexed because the two region filters
            // are the most-used query on this table.
            $table->string('kabupaten', 128)->nullable()->index();
            $table->string('kecamatan', 128)->nullable()->index();
            $table->string('kelurahan', 128)->nullable();
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();

            // Registration/payment flags, kept as free text: the file may say
            // "Sudah", "Y", "1" or "sudah registrasi" and we would rather store
            // what it said than guess wrong.
            $table->string('status_registrasi', 64)->nullable();
            $table->string('status_pembayaran', 64)->nullable();
            $table->string('status_billing_nac', 64)->nullable();
            $table->string('status_registrasi_matkul', 64)->nullable();

            // Derived from the four above (or read directly) via StudentCondition.
            $table->string('kategori_masalah', 48)->default('lainnya')->index();
            $table->string('kategori_masalah_raw', 255)->nullable();

            $table->string('sumber_data', 64)->nullable();
            $table->foreignId('import_id')->nullable()->constrained('student_imports')->nullOnDelete();

            /* ---------- hand-out ---------- */

            $table->string('assignment_status', 24)->default('belum_assigned')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // Set when this student has been linked to a person in the CRM.
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->text('catatan')->nullable();
            $table->json('extra')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The admin's working query is "unassigned + this kabupaten +
            // this condition", which is exactly this index.
            $table->index(['assignment_status', 'kabupaten', 'kategori_masalah'], 'students_handout_index');
            $table->index(['assigned_to', 'assignment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
