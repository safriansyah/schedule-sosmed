<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planned work: what the team intends to do, on a timeline.
 *
 * Deliberately NOT a ticket. A ticket is one person's problem and is closed
 * with a resolution; a task is a piece of scheduled work with a start and a
 * due date, and it is the admin's planning tool. Keeping them apart is what
 * stops "Follow Up Mahasiswa Semester 2026.1" from sitting in the same queue
 * as "mahasiswa X belum bayar".
 *
 * A task MAY reference a ticket (`ticket_id`) when the work grew out of one,
 * but the entities stay separate.
 *
 * `is_public` exposes a task on an unauthenticated page. Only the columns in
 * Task::publicPayload() are ever rendered there — no student, contact or
 * requester data is reachable from a task at all, which is why the public page
 * cannot leak it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->text('description')->nullable();

            // Dates, not datetimes: the timeline is drawn in day columns and a
            // task that starts "at 14:30" would still occupy the whole day.
            $table->date('start_date')->index();
            $table->date('due_date')->index();

            $table->string('status', 24)->default('planned')->index();
            $table->string('priority', 16)->default('normal')->index();

            // The person responsible. Null = unassigned, still on the timeline.
            $table->foreignId('pic_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();

            $table->boolean('is_public')->default(false)->index();
            $table->unsignedTinyInteger('progress')->default(0);

            $table->string('attachment_path', 512)->nullable();
            $table->string('attachment_name')->nullable();

            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The timeline always asks "which tasks overlap this window".
            $table->index(['start_date', 'due_date']);
            $table->index(['is_public', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
