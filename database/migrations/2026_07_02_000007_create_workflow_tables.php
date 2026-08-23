<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Curator decisions
        Schema::create('approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action')->index();              // ApprovalAction
            $table->text('note')->nullable();

            // Optional corrections suggested by the curator
            $table->text('suggested_caption')->nullable();
            $table->text('suggested_hashtags')->nullable();
            $table->timestamp('suggested_schedule_at')->nullable();

            $table->timestamps();

            $table->index(['content_id', 'created_at']);
        });

        // Final checks before publishing
        Schema::create('verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action')->index();              // ApprovalAction (approved | rejected)
            $table->json('checklist')->nullable();          // caption, hashtag, image, video, thumbnail, schedule
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['content_id', 'created_at']);
        });

        // Revision requests sent back to the creative team
        Schema::create('revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('note');
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['content_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisions');
        Schema::dropIfExists('verifications');
        Schema::dropIfExists('approvals');
    }
};
