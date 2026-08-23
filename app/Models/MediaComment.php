<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaComment extends Model
{
    use HasUuids;

    protected $fillable = [
        'account_media_id', 'external_id', 'username', 'full_name', 'avatar_url',
        'is_verified', 'text', 'like_count', 'reply_count', 'commented_at',
    ];

    protected function casts(): array
    {
        return [
            'commented_at' => 'datetime',
            'is_verified' => 'boolean',
        ];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(AccountMedia::class, 'account_media_id');
    }

    public function handle(): string
    {
        return $this->username ? '@'.ltrim($this->username, '@') : 'Tanpa nama';
    }

    /** First letter for the fallback avatar when no picture is available. */
    public function initial(): string
    {
        return strtoupper(mb_substr($this->username ?: '?', 0, 1));
    }
}
