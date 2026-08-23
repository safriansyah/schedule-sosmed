<?php

namespace App\Models;

use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Schedule extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'content_id', 'social_account_id', 'scheduled_at', 'timezone',
        'status', 'published_at', 'external_id', 'permalink',
        'attempts', 'last_attempt_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    /** How long a schedule is considered "being worked on" after a claim. */
    public const CLAIM_MINUTES = 10;

    /** Give up after this many failed attempts to avoid endless retries. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Rows the publisher should attempt right now.
     *
     * Rows claimed within CLAIM_MINUTES are skipped, so a slow run (a video can
     * take minutes) is never picked up twice by the next cron tick.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereIn('status', [ContentStatus::Scheduled->value, ContentStatus::Failed->value])
            ->where('scheduled_at', '<=', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn (Builder $q) => $q
                ->whereNull('last_attempt_at')
                ->orWhere('last_attempt_at', '<=', now()->subMinutes(self::CLAIM_MINUTES)));
    }

    /**
     * Atomically claim this row for the current run. Returns false when another
     * process claimed it first.
     */
    public function claim(): bool
    {
        $claimed = static::whereKey($this->getKey())
            ->where(fn (Builder $q) => $q
                ->whereNull('last_attempt_at')
                ->orWhere('last_attempt_at', '<=', now()->subMinutes(self::CLAIM_MINUTES)))
            ->update(['last_attempt_at' => now()]);

        return $claimed === 1;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ContentStatus::Published->value);
    }

    public function markPublished(string $externalId, ?string $permalink = null): void
    {
        $this->forceFill([
            'status' => ContentStatus::Published,
            'published_at' => now(),
            'external_id' => $externalId,
            'permalink' => $permalink,
            'last_error' => null,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => ContentStatus::Failed,
            'attempts' => $this->attempts + 1,
            'last_attempt_at' => now(),
            'last_error' => $error,
        ])->save();
    }
}
