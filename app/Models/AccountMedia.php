<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A post observed on a connected account (ours or not). */
class AccountMedia extends Model
{
    use HasUuids;

    protected $table = 'account_media';

    protected $fillable = [
        'social_account_id', 'content_id', 'external_id', 'caption',
        'media_type', 'product_type', 'permalink', 'thumbnail_url', 'thumbnail_path', 'posted_at',
        'comments_synced_at',
    ];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime', 'comments_synced_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(MediaMetric::class);
    }

    /**
     * Legacy Instagram-only comments. Superseded by interactions(); kept so the
     * pre-migration rows stay reachable.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(MediaComment::class)->latest('commented_at');
    }

    /** Comments and mentions on this post, from the unified inbox. */
    public function interactions(): MorphMany
    {
        return $this->morphMany(Interaction::class, 'source')->latest('occurred_at');
    }

    /** Most recent snapshot — the "current" numbers. */
    public function latestMetric(): ?MediaMetric
    {
        return $this->metrics()->orderByDesc('captured_on')->first();
    }

    public function scopePostedBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('posted_at', [$from, $to]);
    }

    /** Local copy first — see Interaction::avatar() for why. */
    public function thumbnail(): ?string
    {
        return self::imageUrl($this->thumbnail_path, $this->thumbnail_url);
    }

    /**
     * Same rule, for rows that are not models.
     *
     * Some analytics queries build their rows with DB::table for speed, so
     * they have no accessors — this keeps the "prefer our own copy" decision
     * in one place rather than repeating it in a Blade template.
     */
    public static function imageUrl(?string $path, ?string $url): ?string
    {
        if (filled($path)) {
            return \Illuminate\Support\Facades\Storage::disk('public')->url($path);
        }

        return $url;
    }

    public function isReel(): bool
    {
        return $this->product_type === 'REELS';
    }

    /** Short, single-line caption for tables. */
    public function shortCaption(int $length = 60): string
    {
        return str($this->caption ?: 'Tanpa caption')->replace("\n", ' ')->limit($length)->toString();
    }
}
