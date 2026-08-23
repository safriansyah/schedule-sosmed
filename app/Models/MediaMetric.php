<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Daily snapshot of one post's lifetime insight totals. */
class MediaMetric extends Model
{
    use HasUuids;

    /** Metrics that can be summed and compared across periods. */
    public const METRICS = [
        'likes' => 'Like',
        'comments' => 'Comment',
        'views' => 'Views',
        'reach' => 'Reach',
        'saves' => 'Save',
        'shares' => 'Share',
        'interactions' => 'Interaksi',
    ];

    protected $fillable = [
        'account_media_id', 'captured_on', 'captured_at',
        'likes', 'comments', 'views', 'reach', 'saves', 'shares', 'interactions',
    ];

    protected function casts(): array
    {
        return ['captured_on' => 'date', 'captured_at' => 'datetime'];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(AccountMedia::class, 'account_media_id');
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('captured_on', [$from, $to]);
    }

    public function engagementRate(int $followers): float
    {
        return $followers > 0 ? round($this->interactions / $followers * 100, 2) : 0.0;
    }
}
