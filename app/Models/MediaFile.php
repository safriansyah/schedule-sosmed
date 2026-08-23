<?php

namespace App\Models;

use App\Enums\MediaType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class MediaFile extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'content_id', 'type', 'disk', 'path', 'original_name',
        'mime', 'size', 'width', 'height', 'duration', 'position',
    ];

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration' => 'integer',
            'position' => 'integer',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /** Publicly reachable URL — this is what the platform APIs download. */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function isVideo(): bool
    {
        return $this->type === MediaType::Video;
    }

    /** Aspect ratio (width / height), or null when dimensions are unknown. */
    public function ratio(): ?float
    {
        return $this->width && $this->height ? $this->width / $this->height : null;
    }

    public function humanSize(): string
    {
        $mb = $this->size / 1_048_576;

        return $mb >= 1
            ? number_format($mb, 1).' MB'
            : number_format($this->size / 1024, 0).' KB';
    }
}
