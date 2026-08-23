<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatasetItem extends Model
{
    protected $fillable = [
        'dataset_id',
        'external_id',
        'name',
        'username',
        'platform',
        'followers',
        'following',
        'posts',
        'is_valid',
        'is_qualified',
        'profile_url',
        'reason',
        'extra',
    ];

    protected function casts(): array
    {
        return [
            'followers' => 'integer',
            'following' => 'integer',
            'posts' => 'integer',
            'is_valid' => 'boolean',
            'is_qualified' => 'boolean',
            'extra' => 'array',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }
}
