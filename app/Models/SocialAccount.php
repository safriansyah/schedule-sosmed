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
        'media_cursor', 'media_backfilled_at',
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
            'media_backfilled_at' => 'datetime',
        ];
    }

    /**
     * Profile picture to render — our own copy first.
     *
     * The remote URL is signed and expires within days, and it is only written
     * when the account is verified, so it is stale almost immediately.
     */
    public function avatar(): ?string
    {
        if (filled($this->avatar_path)) {
            return \Illuminate\Support\Facades\Storage::disk('public')->url($this->avatar_path);
        }

        return $this->avatar_url;
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
