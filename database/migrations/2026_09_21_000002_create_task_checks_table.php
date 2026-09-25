<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One tick on the task planner: "this task was worked on, this day".
 *
 * The planner is a grid — tasks down, dates across, a ✓ in the cell. A task's
 * start/due range already says which days were PLANNED; this table records
 * which ones actually happened, which is the thing a supervisor reads the
 * grid for. Keeping them apart means moving a deadline never silently
 * rewrites what the team reported doing.
 *
 * (task_id, date) is unique, so ticking the same cell twice is a no-op rather
 * than a second row — two people looking at the same grid can both click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_checks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            // Who ticked it, kept for the activity trail. Nullable so deleting
            // a user empties the name rather than the record of the work.
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();

            $table->timestamps();

            $table->unique(['task_id', 'date']);
            // The grid reads a date window across every task at once.
            $table->index(['date', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checks');
    }
};
