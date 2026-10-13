<?php

namespace App\Models;

use App\Enums\LeadSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One lead spreadsheet upload and its outcome. */
class LeadImport extends Model
{
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /** Beyond this the error list is truncated; the counters stay exact. */
    public const MAX_ERRORS = 500;

    protected $fillable = [
        'original_name', 'path', 'extension', 'sheet', 'source', 'status',
        'total_rows', 'imported_count', 'updated_count', 'failed_count', 'duplicate_count',
        'mapping', 'errors', 'error_message', 'created_by', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'mapping' => 'array',
            'errors' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'import_id');
    }

    public function awaitingConfirmation(): bool
    {
        return $this->status === self::STATUS_UPLOADED;
    }

    public function successCount(): int
    {
        return $this->imported_count + $this->updated_count;
    }
}
