<?php

namespace App\Models;

use App\Enums\ApprovalAction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Verification extends Model
{
    use HasUuids;

    /** Items the verifier ticks off before content may publish. */
    public const CHECKLIST = [
        'caption' => 'Caption benar',
        'hashtag' => 'Hashtag benar',
        'image' => 'Gambar benar',
        'video' => 'Video benar',
        'thumbnail' => 'Thumbnail benar',
        'schedule' => 'Jadwal benar',
    ];

    protected $fillable = ['content_id', 'user_id', 'action', 'checklist', 'note'];

    protected function casts(): array
    {
        return [
            'action' => ApprovalAction::class,
            'checklist' => 'array',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** True when every checklist item was ticked. */
    public function isComplete(): bool
    {
        $checked = collect($this->checklist ?? []);

        return collect(self::CHECKLIST)
            ->keys()
            ->every(fn (string $key) => (bool) $checked->get($key, false));
    }
}
