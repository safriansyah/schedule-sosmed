<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One upload of the student spreadsheet, and what became of every row in it.
 *
 * Kept as its own record rather than a flash message because a 5.000-row
 * import is not something you read once: the admin needs to come back the next
 * morning and answer "which 40 rows failed, and why". `errors` holds up to
 * `max_errors` rows of {line, nim, reason}; the count columns stay accurate
 * even when that list is truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_imports', function (Blueprint $table) {
            $table->id();

            $table->string('original_name');
            $table->string('path', 512);            // stored upload, kept for re-runs
            $table->string('extension', 8)->nullable();

            // pending | processing | completed | failed
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedTinyInteger('progress')->default(0);

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);

            // Which spreadsheet header fed which column, so a corrected mapping
            // can be replayed against the same file.
            $table->json('mapping')->nullable();
            $table->json('errors')->nullable();
            $table->string('error_message', 1000)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_imports');
    }
};
