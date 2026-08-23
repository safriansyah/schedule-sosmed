<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'subject_type', 'subject_id', 'action',
        'description', 'properties', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }

    /** Icon shown on the activity timeline, derived from the action prefix. */
    public function icon(): string
    {
        return match (true) {
            str_contains($this->action, 'approv') => 'check-circle',
            str_contains($this->action, 'verif') => 'badge-check',
            str_contains($this->action, 'revis') => 'rotate',
            str_contains($this->action, 'publish') => 'send',
            str_contains($this->action, 'delete') => 'trash',
            str_contains($this->action, 'creat') => 'plus',
            str_contains($this->action, 'login') => 'logout',
            default => 'activity',
        };
    }
}
