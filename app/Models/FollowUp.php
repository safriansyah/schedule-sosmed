<?php

namespace App\Models;

use App\Enums\FollowUpAction;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One touch in a follow-up history. Append-only by convention — corrections
 * are added as a new entry rather than editing an old one, so the record of
 * who did what stays honest.
 */
class FollowUp extends Model
{
    protected $fillable = [
        'followupable_type', 'followupable_id', 'user_id', 'role_at_time',
        'channel_used', 'action', 'response_text', 'outcome', 'next_action_at',
        'status_after', 'additional_data', 'attachment_path', 'attachment_name',
    ];

    protected function casts(): array
    {
        return [
            'action' => FollowUpAction::class,
            'outcome' => FollowUpOutcome::class,
            'status_after' => FollowUpStatus::class,
            'next_action_at' => 'datetime',
            'additional_data' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Stamp the author's role at the moment of writing; people get
        // promoted and the log must not silently rewrite itself.
        static::creating(function (self $followUp) {
            $followUp->role_at_time ??= $followUp->user?->role?->name?->value;
        });
    }

    public function followupable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Touches with a reminder date that has come due. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereNotNull('next_action_at')->where('next_action_at', '<=', now());
    }

    /* -----------------------------------------------------------------
     | Additional data
     * ----------------------------------------------------------------- */

    /**
     * The extra facts an operator picked up during this touch, as
     * label => value pairs.
     *
     * This is a note ON one touch, never the system of record: a new phone
     * number recorded here is also written onto the ticket/student row and the
     * change is logged with its old value, so nothing here needs to be read
     * back to know the current state.
     *
     * @return array<string, string>
     */
    public function additionalPairs(): array
    {
        return collect($this->additional_data ?? [])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => is_scalar($value) ? (string) $value : json_encode($value))
            ->all();
    }

    public function hasAttachment(): bool
    {
        return filled($this->attachment_path);
    }
}
