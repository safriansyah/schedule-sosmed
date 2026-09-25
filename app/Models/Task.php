<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A planned piece of work on the team timeline.
 *
 * Separate from Ticket on purpose — see the migration.
 */
class Task extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'description', 'start_date', 'due_date', 'status', 'priority',
        'pic_id', 'created_by', 'ticket_id', 'is_public', 'progress',
        'attachment_path', 'attachment_name', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'status' => TaskStatus::class,
            'priority' => Priority::class,
            'is_public' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Completing a task stamps the time; re-opening one clears it, so the
        // two can never disagree.
        static::saving(function (self $task) {
            if ($task->status === TaskStatus::Completed) {
                $task->completed_at ??= now();
            } else {
                $task->completed_at = null;
            }
        });
    }

    /* -----------------------------------------------------------------
     | Relations
     * ----------------------------------------------------------------- */

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Optional origin. A task is never required to have one. */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** The ✓ marks on the planner — one row per day worked. */
    public function checks(): HasMany
    {
        return $this->hasMany(TaskCheck::class)->orderBy('date');
    }

    /* -----------------------------------------------------------------
     | Scopes
     * ----------------------------------------------------------------- */

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * Tasks that touch the window at all — including ones that started before
     * it and are still running, which a naive whereBetween would miss.
     */
    public function scopeOverlapping(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->where('start_date', '<=', $to->toDateString())
            ->where('due_date', '>=', $from->toDateString());
    }

    /** @param array<string, mixed> $filters */
    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        return $query
            ->when(filled($filters['q'] ?? null), function (Builder $q) use ($filters) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filters['q'])).'%';
                $q->where(fn (Builder $w) => $w->where('title', 'like', $like)->orWhere('description', 'like', $like));
            })
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['priority'] ?? null), fn ($q) => $q->where('priority', $filters['priority']))
            ->when(($filters['pic'] ?? '') !== '', function (Builder $q) use ($filters) {
                return (string) $filters['pic'] === '0'
                    ? $q->whereNull('pic_id')
                    : $q->where('pic_id', (int) $filters['pic']);
            })
            ->when(($filters['visibility'] ?? '') !== '', fn ($q) => $q->where('is_public', $filters['visibility'] === 'public'));
    }

    /* -----------------------------------------------------------------
     | Helpers
     * ----------------------------------------------------------------- */

    /** Inclusive, so a one-day task spans 1 day rather than 0. */
    public function durationDays(): int
    {
        return $this->start_date->diffInDays($this->due_date) + 1;
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->due_date->isPast();
    }

    /**
     * Exactly what the unauthenticated page is allowed to render.
     *
     * The public view builds its cards from this array and nothing else. A
     * task has no relation to a student or a contact in the first place, so
     * there is no personal data to leak here — but the whitelist means a
     * column added later is private until someone deliberately adds it.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'start_date' => $this->start_date,
            'due_date' => $this->due_date,
            'status' => $this->status,
            'progress' => $this->progress,
        ];
    }
}
