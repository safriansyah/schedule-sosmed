<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One account (handle) belonging to a contact on one network. */
class ContactIdentity extends Model
{
    protected $fillable = [
        'contact_id', 'channel', 'handle', 'external_id', 'is_primary', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => SocialPlatform::class,
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** Display form: @handle for socials, the bare number for WhatsApp. */
    public function display(): string
    {
        if ($this->channel === SocialPlatform::WhatsApp) {
            return $this->handle ?? '—';
        }

        return $this->handle ? '@'.ltrim($this->handle, '@') : ($this->external_id ?? '—');
    }
}
