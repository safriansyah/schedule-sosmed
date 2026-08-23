<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per (content × target account). This is what the publisher
     * actually works through, and it keeps multi-platform posting normalised.
     */
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignUuid('social_account_id')->constrained('social_accounts')->cascadeOnDelete();

            $table->timestamp('scheduled_at')->index();
            $table->string('timezone')->default('Asia/Jakarta');

            $table->string('status')->default('scheduled')->index(); // ContentStatus subset
            $table->timestamp('published_at')->nullable();

            $table->string('external_id')->nullable();      // platform post id
            $table->string('permalink', 1024)->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['content_id', 'social_account_id']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
