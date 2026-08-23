<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('title');
            $table->text('caption')->nullable();
            $table->text('hashtags')->nullable();       // space separated, stored raw
            $table->string('mention')->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_carousel')->default(false);

            $table->string('status')->default('draft')->index();   // ContentStatus

            // Workflow participants
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('curated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            // Primary schedule (per-account attempts live in `schedules`)
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('published_at')->nullable()->index();

            $table->text('internal_note')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
