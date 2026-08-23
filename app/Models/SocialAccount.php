<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialAccount extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'platform', 'name', 'username', 'external_id',
        'access_token', 'refresh_token', 'token_expires_at',
        'avatar_url', 'followers_count', 'media_count',
        'is_active', 'meta',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'meta' => 'array',
            'followers_count' => 'integer',
            'media_count' => 'integer',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(AccountMetric::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePlatform($query, SocialPlatform $platform)
    {
        return $query->where('platform', $platform->value);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at?->isPast() ?? false;
    }

    public function handle(): string
    {
        return $this->username ? '@'.ltrim($this->username, '@') : $this->name;
    }
}
