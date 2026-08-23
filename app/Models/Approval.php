<?php

namespace App\Models;

use App\Enums\ApprovalAction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    use HasUuids;

    protected $fillable = [
        'content_id', 'user_id', 'action', 'note',
        'suggested_caption', 'suggested_hashtags', 'suggested_schedule_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => ApprovalAction::class,
            'suggested_schedule_at' => 'datetime',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function curator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function hasSuggestions(): bool
    {
        return filled($this->suggested_caption)
            || filled($this->suggested_hashtags)
            || filled($this->suggested_schedule_at);
    }
}
