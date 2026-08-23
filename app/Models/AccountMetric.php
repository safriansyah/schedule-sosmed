<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Daily snapshot of an account's totals — powers period comparisons. */
class AccountMetric extends Model
{
    use HasUuids;

    /** Metrics that can be summed/compared across periods. */
    public const METRICS = [
        'likes' => 'Like',
        'comments' => 'Comment',
        'views' => 'Views',
        'reach' => 'Reach',
        'impressions' => 'Impression',
        'shares' => 'Share',
        'saves' => 'Save',
    ];

    protected $fillable = [
        'social_account_id', 'captured_on', 'captured_at',
        'followers', 'follows', 'media_count',
        'likes', 'comments', 'views', 'reach', 'impressions', 'shares', 'saves',
        'engagement_rate',
    ];

    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
            'captured_at' => 'datetime',
            'engagement_rate' => 'float',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('captured_on', [$from, $to]);
    }
}
