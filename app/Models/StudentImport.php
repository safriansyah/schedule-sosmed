<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One spreadsheet upload and its outcome. See the migration for the shape. */
class StudentImport extends Model
{
    /** Uploaded and previewed, but "Import Sekarang" has not been pressed. */
    public const STATUS_UPLOADED = 'uploaded';

    /** Confirmed and waiting for the queue worker. */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /** Beyond this the error list is truncated; the counters stay exact. */
    public const MAX_ERRORS = 500;

    protected $fillable = [
        'original_name', 'path', 'extension', 'sheet', 'status', 'progress',
        'total_rows', 'imported_count', 'updated_count', 'failed_count', 'duplicate_count',
        'mapping', 'errors', 'error_message', 'created_by', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'errors' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'import_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /** Not confirmed yet: belongs on the preview page, not the result page. */
    public function awaitingConfirmation(): bool
    {
        return $this->status === self::STATUS_UPLOADED;
    }

    /**
     * Queued or running, but nothing has happened for too long: the worker is
     * not running, or its process was killed mid-import. Progress is saved
     * every chunk, so a live import never goes this quiet.
     */
    public function isStalled(): bool
    {
        return match ($this->status) {
            self::STATUS_PENDING => $this->updated_at?->lt(now()->subSeconds(30)) ?? false,
            self::STATUS_PROCESSING => $this->updated_at?->lt(now()->subMinutes(5)) ?? false,
            default => false,
        };
    }

    /** Rows that made it in, either as new students or as updates. */
    public function successCount(): int
    {
        return $this->imported_count + $this->updated_count;
    }
}
