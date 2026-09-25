<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The unified inbox: every inbound message from every channel.
 *
 * Replaces `media_comments`, which could only ever hold Instagram comments
 * because it was a child of `account_media`. A DM has no post, so the link to
 * a source is optional and polymorphic here — comments point at an
 * AccountMedia, DMs point at nothing (or later, a thread).
 *
 * Three groups of columns, in order: what came in, what the AI made of it,
 * and what a human did about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interactions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /* ---------- what came in ---------- */

            $table->string('channel', 32)->index();   // SocialPlatform value
            $table->string('type', 24)->index();      // comment|dm|mention|reply|manual
            $table->string('direction', 12)->default('inbound');

            // Nullable: an interaction is stored the moment it arrives, and the
            // contact is resolved right after. A failed match must never cost us
            // the comment itself.
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->nullableUuidMorphs('source');     // → account_media for comments

            $table->string('external_id')->nullable();

            // Raw author snapshot, kept even once contact_id is set: handles get
            // renamed and avatars expire, and we want to see what it looked like
            // at the time.
            $table->string('author_handle')->nullable()->index();
            $table->string('author_name')->nullable();
            $table->string('author_avatar', 1024)->nullable();
            $table->boolean('author_verified')->default(false);

            $table->text('text')->nullable();
            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('reply_count')->default(0);
            $table->timestamp('occurred_at')->nullable()->index();

            /* ---------- what the AI made of it ---------- */

            $table->string('sentiment', 16)->nullable()->index();  // positive|negative|neutral
            $table->string('intent', 24)->nullable()->index();     // question|praise|complaint|disparagement|spam|other
            $table->boolean('is_urgent')->default(false);
            $table->unsignedTinyInteger('urgency_score')->default(0);
            $table->unsignedTinyInteger('lead_potential')->default(0);
            $table->boolean('needs_reply')->default(false);

            $table->string('ai_model', 64)->nullable();
            $table->unsignedTinyInteger('ai_confidence')->default(0);
            $table->timestamp('ai_classified_at')->nullable();
            $table->json('ai_raw')->nullable();

            // A human disagreeing with the classifier. Set here rather than
            // overwriting `sentiment` so we keep both the machine's answer and
            // the correction — the corrections are what we feed back as
            // few-shot examples to make the next run better.
            $table->string('sentiment_override', 16)->nullable();
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('override_at')->nullable();

            /* ---------- what a human did about it ---------- */

            $table->string('status', 24)->default('new')->index();  // new|in_progress|replied|done|ignored
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One row per message per channel, so a re-sync updates instead of
            // duplicating.
            $table->unique(['channel', 'external_id']);

            // The inbox is almost always read as "urgent, unhandled, newest
            // first" or "this status, newest first" — index for exactly that.
            $table->index(['status', 'is_urgent', 'occurred_at']);
            $table->index(['channel', 'status', 'occurred_at']);
            // Finds the classifier's backlog without a full scan.
            $table->index(['ai_classified_at', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interactions');
    }
};
