<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UT Analytic Sosmed — bulk social-account datasets uploaded as JSON, then
 * validated and scored. Counters are denormalised onto `datasets` so list
 * screens stay O(1) even with hundreds of thousands of items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datasets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();

            // Raw source kept so an import can be replayed.
            $table->string('source_filename')->nullable();
            $table->string('source_path')->nullable();

            // Import lifecycle
            $table->string('status')->default('pending')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('imported_at')->nullable();

            // Denormalised counters
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('qualified_count')->default(0);

            $table->json('meta')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('dataset_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('dataset_id')->constrained('datasets')->cascadeOnDelete();

            $table->string('external_id')->nullable();   // e.g. NIM
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->string('platform', 64)->nullable();

            $table->unsignedBigInteger('followers')->default(0);
            $table->unsignedBigInteger('following')->default(0);
            $table->unsignedBigInteger('posts')->default(0);

            $table->boolean('is_valid')->default(false);
            $table->boolean('is_qualified')->default(false);

            $table->string('profile_url', 512)->nullable();
            $table->string('reason')->nullable();

            // Anything else from the source row, untouched.
            $table->json('extra')->nullable();

            $table->timestamps();

            // Indexes matching the analytics queries.
            $table->index(['dataset_id', 'is_valid']);
            $table->index(['dataset_id', 'is_qualified']);
            $table->index(['dataset_id', 'platform']);
            $table->index(['dataset_id', 'followers']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_items');
        Schema::dropIfExists('datasets');
    }
};
