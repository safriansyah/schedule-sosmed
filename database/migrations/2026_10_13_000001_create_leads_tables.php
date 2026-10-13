<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data Lead: prospective students collected by the marketing side — Meta and
 * TikTok ads, the sosprom attendance sheets, partners, and the lists sent by
 * UT Pusat — imported from spreadsheets the same way the student list is.
 *
 * One lead per phone number: the number is the only thing every source
 * carries, and calling the same person twice because two lists both had them
 * is exactly what a lead list should prevent. `sumber` records where the lead
 * FIRST came from; a later list that repeats them fills gaps but does not
 * claim them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_imports', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('path', 512);
            $table->string('extension', 8)->nullable();
            $table->string('sheet')->nullable();
            // The source is picked BEFORE the upload and applies to every row.
            $table->string('source', 32)->index();

            // uploaded | processing | completed | failed
            $table->string('status', 24)->default('uploaded')->index();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);

            $table->json('mapping')->nullable();
            $table->json('errors')->nullable();
            $table->string('error_message', 1000)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('no_hp', 20)->unique();      // 62…, see PhoneNumber
            $table->string('kota')->nullable()->index(); // kota / kabupaten
            $table->string('asal_sekolah')->nullable();
            $table->string('sumber', 32)->index();
            $table->json('extra')->nullable();          // columns nobody mapped
            $table->foreignId('import_id')->nullable()->constrained('lead_imports')->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_imports');
    }
};
