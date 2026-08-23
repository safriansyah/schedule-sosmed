<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A post observed on a connected account (ours or not). */
class AccountMedia extends Model
{
    use HasUuids;

    protected $table = 'account_media';

    protected $fillable = [
        'social_account_id', 'content_id', 'external_id', 'caption',
        'media_type', 'product_type', 'permalink', 'thumbnail_url', 'posted_at',
    ];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
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

    public function comments(): HasMany
    {
        return $this->hasMany(MediaComment::class)->latest('commented_at');
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
