<?php

namespace App\Models;

use App\Enums\ContactStatus;
use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A person. The hub every other CRM record hangs off — see the migration for
 * why this is a person rather than an account.
 */
class Contact extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * Excludes `status`, `agent_code`, `agent_since` and `code`: promoting
     * someone to agent is a privileged action with its own method, never
     * something a stray request field can do.
     *
     * @var list<string>
     */
    protected $fillable = [
        'display_name', 'full_name', 'avatar_url', 'avatar_path', 'phone_e164', 'email',
        'region_id', 'address_detail', 'potential_score', 'owner_id', 'notes',
        'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContactStatus::class,
            'agent_since' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'potential_score' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $contact) {
            $contact->code ??= self::nextCode();
        });
    }

    /* -----------------------------------------------------------------
     | Relations
     * ----------------------------------------------------------------- */

    public function identities(): HasMany
    {
        return $this->hasMany(ContactIdentity::class);
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class)->latest('occurred_at');
    }

    public function followUps(): MorphMany
    {
        return $this->morphMany(FollowUp::class, 'followupable')->latest();
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recruited_by');
    }

    /* -----------------------------------------------------------------
     | Scopes
     * ----------------------------------------------------------------- */

    public function scopeAgents(Builder $query): Builder
    {
        return $query->where('status', ContactStatus::Agent->value);
    }

    /** Hides rows that were merged away, so lists never show a duplicate. */
    public function scopeCanonical(Builder $query): Builder
    {
        return $query->whereNull('merged_into_id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(fn (Builder $q) => $q
            ->where('full_name', 'like', $like)
            ->orWhere('display_name', 'like', $like)
            ->orWhere('code', 'like', $like)
            ->orWhere('phone_e164', 'like', $like)
            ->orWhereHas('identities', fn (Builder $i) => $i->where('handle', 'like', $like)));
    }

    /* -----------------------------------------------------------------
     | Behaviour
     * ----------------------------------------------------------------- */

    public function isAgent(): bool
    {
        return $this->status === ContactStatus::Agent;
    }

    /** Best name we have: the operator's entry wins over the platform's. */
    public function name(): string
    {
        return $this->full_name
            ?: $this->display_name
            ?: $this->identities->first()?->display()
            ?: $this->code;
    }

    /** Local copy first — see Interaction::avatar() for why. */
    public function avatar(): ?string
    {
        if (filled($this->avatar_path)) {
            return \Illuminate\Support\Facades\Storage::disk('public')->url($this->avatar_path);
        }

        return $this->avatar_url;
    }

    public function initial(): string
    {
        return strtoupper(mb_substr($this->name(), 0, 1));
    }

    /** The handle on one network, if we know it. */
    public function handleFor(SocialPlatform $channel): ?string
    {
        return $this->identities->firstWhere('channel', $channel)?->handle;
    }

    /**
     * Promote to agent. Kept here rather than in a controller so the code,
     * timestamp and recruiter can never be set inconsistently.
     */
    public function promoteToAgent(User $by): void
    {
        $this->forceFill([
            'status' => ContactStatus::Agent,
            'agent_code' => $this->agent_code ?? self::nextAgentCode(),
            'agent_since' => $this->agent_since ?? now(),
            'recruited_by' => $by->id,
        ])->save();
    }

    public function demoteFromAgent(): void
    {
        // agent_code is kept: it may already be printed on materials, and
        // re-promoting the same person should give back the same code.
        $this->forceFill(['status' => ContactStatus::NonAgent])->save();
    }

    /* -----------------------------------------------------------------
     | Identifiers
     * ----------------------------------------------------------------- */

    /**
     * Next sequential UID, e.g. UT-000142.
     *
     * MAX+1 rather than a counter table. Two simultaneous inserts could pick
     * the same number, but the UNIQUE index rejects the loser rather than
     * letting a duplicate through, and ContactResolver retries.
     */
    public static function nextCode(): string
    {
        $last = (int) DB::table('contacts')
            ->selectRaw('MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) as n')
            ->where('code', 'like', 'UT-%')
            ->value('n');

        return 'UT-'.str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);
    }

    public static function nextAgentCode(): string
    {
        $last = (int) DB::table('contacts')
            ->selectRaw('MAX(CAST(SUBSTRING(agent_code, 5) AS UNSIGNED)) as n')
            ->where('agent_code', 'like', 'AGT-%')
            ->value('n');

        return 'AGT-'.str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
    }
}
