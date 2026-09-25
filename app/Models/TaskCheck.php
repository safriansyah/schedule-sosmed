<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ✓ on the planner: this task was worked on, this day.
 *
 * Kept apart from the task's start/due range on purpose. The range is the
 * plan; these are the facts. Moving a deadline must never rewrite what the
 * team reported having done.
 */
class TaskCheck extends Model
{
    protected $fillable = ['task_id', 'date', 'checked_by', 'note'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
