<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dataset extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'source_filename',
        'source_path',
        'status',
        'progress',
        'error_message',
        'imported_at',
        'total_rows',
        'valid_count',
        'invalid_count',
        'qualified_count',
        'meta',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'imported_at' => 'datetime',
            'progress' => 'integer',
            'total_rows' => 'integer',
            'valid_count' => 'integer',
            'invalid_count' => 'integer',
            'qualified_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function items(): HasMany
    {
        return $this->hasMany(DatasetItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function getQualifiedRateAttribute(): float
    {
        return $this->total_rows > 0
            ? round($this->qualified_count / $this->total_rows * 100, 1)
            : 0.0;
    }

    public function getValidRateAttribute(): float
    {
        return $this->total_rows > 0
            ? round($this->valid_count / $this->total_rows * 100, 1)
            : 0.0;
    }
}
