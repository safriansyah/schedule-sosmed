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
     *
     * Bounded at BOTH ends. The lower bound is the important one: the failure
     * mode of a stopped cron is not "nothing happens", it is "everything
     * happens at once when it comes back", and a month-old promo going live as
     * if it were current is worse than not publishing at all. Anything older
     * than the window is left for staleness() to fail explicitly.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereIn('status', [ContentStatus::Scheduled->value, ContentStatus::Failed->value])
            ->where('scheduled_at', '<=', now())
            ->tap(fn (Builder $q) => self::withinDelayWindow($q))
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn (Builder $q) => $q
                ->whereNull('last_attempt_at')
                ->orWhere('last_attempt_at', '<=', now()->subMinutes(self::CLAIM_MINUTES)));
    }

    /**
     * Due, but too late to publish safely — these need a human decision.
     *
     * Deliberately not filtered by attempts or claim time: a stale row is
     * stale regardless of how often it was tried.
     */
    public function scopeStale(Builder $query): Builder
    {
        $cutoff = self::delayCutoff();

        if ($cutoff === null) {
            // Guard disabled — nothing is ever considered stale.
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('status', [ContentStatus::Scheduled->value, ContentStatus::Failed->value])
            ->where('scheduled_at', '<', $cutoff);
    }

    /** Schedule rows whose content has left the schedulable states. */
    public function scopeOrphaned(Builder $query): Builder
    {
        return $query
            ->whereNotIn('status', [ContentStatus::Published->value, ContentStatus::Cancelled->value])
            ->whereHas('content', fn (Builder $q) => $q->whereNotIn('status', [
                ContentStatus::Scheduled->value,
                ContentStatus::Failed->value,
                ContentStatus::Published->value,
            ]));
    }

    /** Oldest scheduled_at still allowed to publish, or null when disabled. */
    public static function delayCutoff(): ?\Illuminate\Support\Carbon
    {
        $hours = (int) config('publishing.max_delay_hours', 24);

        return $hours > 0 ? now()->subHours($hours) : null;
    }

    private static function withinDelayWindow(Builder $query): void
    {
        if ($cutoff = self::delayCutoff()) {
            $query->where('scheduled_at', '>=', $cutoff);
        }
    }

    /** How many hours past its slot this row is. */
    public function hoursLate(): float
    {
        return round($this->scheduled_at->diffInMinutes(now()) / 60, 1);
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
