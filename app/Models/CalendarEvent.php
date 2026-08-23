<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CalendarEvent extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPES = [
        'note' => 'Catatan',
        'reminder' => 'Reminder',
        'deadline' => 'Deadline',
    ];

    protected $fillable = [
        'user_id', 'content_id', 'title', 'type', 'note',
        'color', 'starts_at', 'ends_at', 'is_all_day',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_all_day' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('starts_at', [$from, $to]);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function displayColor(): string
    {
        return $this->color ?: match ($this->type) {
            'reminder' => '#f59e0b',
            'deadline' => '#ef4444',
            default => '#8b5cf6',
        };
    }
}
