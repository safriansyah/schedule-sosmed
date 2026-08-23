<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Enums\MediaType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Content extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'title', 'caption', 'hashtags', 'mention', 'location', 'is_carousel',
        'status', 'created_by', 'curated_by', 'verified_by',
        'scheduled_at', 'published_at', 'internal_note', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'is_carousel' => 'boolean',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /* -----------------------------------------------------------------
     | Relations
     * ----------------------------------------------------------------- */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function curator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'curated_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function media(): HasMany
    {
        return $this->hasMany(MediaFile::class)->orderBy('position');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class)->latest();
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class)->latest();
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class)->latest();
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ContentMetric::class);
    }

    /* -----------------------------------------------------------------
     | Scopes
     * ----------------------------------------------------------------- */

    public function scopeStatus(Builder $query, ContentStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof ContentStatus ? $status->value : $status);
    }

    /** Content still moving through the workflow. */
    public function scopeInPipeline(Builder $query): Builder
    {
        return $query->whereIn('status', array_column(ContentStatus::pipeline(), 'value'));
    }

    public function scopeCreatedBy(Builder $query, int $userId): Builder
    {
        return $query->where('created_by', $userId);
    }

    /** Everything the publisher should attempt now. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->status(ContentStatus::Scheduled)
            ->where('scheduled_at', '<=', now());
    }

    /* -----------------------------------------------------------------
     | Helpers
     * ----------------------------------------------------------------- */

    /** The media used as the post payload (first image or video). */
    public function primaryMedia(): ?MediaFile
    {
        return $this->media->first(fn (MediaFile $m) => $m->type->isPayload());
    }

    public function hasVideo(): bool
    {
        return $this->media->contains(fn (MediaFile $m) => $m->type === MediaType::Video);
    }

    /** Caption assembled the way it will be posted: caption + hashtags. */
    public function composedCaption(): string
    {
        return collect([trim((string) $this->caption), trim((string) $this->hashtags)])
            ->filter(fn ($part) => $part !== '')
            ->implode("\n\n");
    }

    public function isEditableBy(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->created_by === $user->id && $this->status->isEditableByCreative();
    }
}
