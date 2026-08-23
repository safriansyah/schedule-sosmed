<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-post insights, captured daily while the post is fresh. */
class ContentMetric extends Model
{
    use HasUuids;

    protected $fillable = [
        'content_id', 'schedule_id', 'captured_on',
        'likes', 'comments', 'views', 'reach', 'impressions', 'shares', 'saves',
        'engagement_rate',
    ];

    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
            'engagement_rate' => 'float',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('captured_on', [$from, $to]);
    }

    /** Total interactions — the numerator of the engagement rate. */
    public function interactions(): int
    {
        return $this->likes + $this->comments + $this->shares + $this->saves;
    }
}
