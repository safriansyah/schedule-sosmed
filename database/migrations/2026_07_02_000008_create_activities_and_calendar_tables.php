<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit log — every status change and notable action lands here.
        Schema::create('activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->nullableUuidMorphs('subject');          // subject_type + subject_id
            $table->string('action')->index();              // e.g. content.submitted
            $table->string('description');
            $table->json('properties')->nullable();         // before/after, extra context

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        // Calendar notes, reminders and deadlines
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('content_id')->nullable()->constrained('contents')->cascadeOnDelete();

            $table->string('title');
            $table->string('type')->default('note')->index(); // note | reminder | deadline
            $table->text('note')->nullable();
            $table->string('color', 16)->nullable();

            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_all_day')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('activities');
    }
};
