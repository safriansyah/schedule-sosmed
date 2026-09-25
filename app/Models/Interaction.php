<?php

namespace App\Models;

use App\Enums\Intent;
use App\Enums\InteractionStatus;
use App\Enums\InteractionType;
use App\Enums\Sentiment;
use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One inbound message, from any channel. See the migration for the shape. */
class Interaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'channel', 'type', 'direction', 'contact_id', 'source_type', 'source_id',
        'external_id', 'author_handle', 'author_external_id', 'author_name', 'author_avatar', 'author_verified',
        'author_avatar_path', 'text', 'like_count', 'reply_count', 'occurred_at',
        'sentiment', 'intent', 'is_urgent', 'urgency_score', 'lead_potential',
        'needs_reply', 'ai_model', 'ai_confidence', 'ai_classified_at', 'ai_raw',
        'status', 'assigned_to', 'first_response_at', 'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'channel' => SocialPlatform::class,
            'type' => InteractionType::class,
            'sentiment' => Sentiment::class,
            'sentiment_override' => Sentiment::class,
            'intent' => Intent::class,
            'status' => InteractionStatus::class,
            'is_urgent' => 'boolean',
            'needs_reply' => 'boolean',
            'author_verified' => 'boolean',
            'ai_raw' => 'array',
            'occurred_at' => 'datetime',
            'ai_classified_at' => 'datetime',
            'override_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /* -----------------------------------------------------------------
     | Relations
     * ----------------------------------------------------------------- */

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** The post a comment was left on. Null for DMs. */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function overrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by');
    }

    public function followUps(): MorphMany
    {
        return $this->morphMany(FollowUp::class, 'followupable')->latest();
    }

    /**
     * The ticket raised from this comment, if any.
     *
     * hasOne rather than hasMany: TicketService::createFromInteraction is
     * idempotent, so one interaction never grows a second ticket.
     */
    public function ticket(): HasOne
    {
        return $this->hasOne(Ticket::class);
    }

    /* -----------------------------------------------------------------
     | Scopes
     * ----------------------------------------------------------------- */

    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', 'inbound');
    }

    public function scopeUrgent(Builder $query): Builder
    {
        return $query->where('is_urgent', true);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InteractionStatus::New->value,
            InteractionStatus::InProgress->value,
        ]);
    }

    /**
     * Comments nobody has escalated yet.
     *
     * The inbox queues mean "still needs someone to pick this up". Once a
     * comment has become a ticket it HAS been picked up — the work now lives
     * in ticketing, and leaving it in the queue as well would show the
     * operator the same angry comment every morning and keep the red badge
     * permanently lit.
     *
     * Applied to the working queues only. "Semua" still shows everything.
     */
    public function scopeWithoutTicket(Builder $query): Builder
    {
        return $query->whereDoesntHave('ticket');
    }

    /** Not yet seen by the classifier — the job's work queue. */
    public function scopeUnclassified(Builder $query): Builder
    {
        return $query->whereNull('ai_classified_at')->whereNotNull('text');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(fn (Builder $q) => $q
            ->where('text', 'like', $like)
            ->orWhere('author_handle', 'like', $like)
            ->orWhere('author_name', 'like', $like));
    }

    /* -----------------------------------------------------------------
     | Behaviour
     * ----------------------------------------------------------------- */

    /** The sentiment to display: a human's correction beats the classifier. */
    public function effectiveSentiment(): ?Sentiment
    {
        return $this->sentiment_override ?? $this->sentiment;
    }

    public function wasOverridden(): bool
    {
        return $this->sentiment_override !== null
            && $this->sentiment_override !== $this->sentiment;
    }

    /**
     * The avatar to actually render.
     *
     * Prefers our own copy: the remote URL is signed and expires after about a
     * fortnight, after which it silently returns nothing. Falls back to the
     * remote link for rows synced before caching existed.
     */
    public function avatar(): ?string
    {
        if (filled($this->author_avatar_path)) {
            return \Illuminate\Support\Facades\Storage::disk('public')->url($this->author_avatar_path);
        }

        return $this->author_avatar;
    }

    public function handle(): string
    {
        return $this->author_handle ? '@'.ltrim($this->author_handle, '@') : 'Tanpa nama';
    }

    public function initial(): string
    {
        return strtoupper(mb_substr($this->author_name ?: $this->author_handle ?: '?', 0, 1));
    }

    /**
     * Hours between the message arriving and the first human response — null
     * while it is still unanswered. Drives the SLA figures.
     */
    public function responseHours(): ?float
    {
        if (! $this->occurred_at || ! $this->first_response_at) {
            return null;
        }

        return round($this->occurred_at->diffInMinutes($this->first_response_at) / 60, 1);
    }

    /**
     * How long this has been waiting, in hours. Used to colour the queue —
     * an urgent item ageing past its target should look different.
     */
    public function waitingHours(): ?float
    {
        if ($this->first_response_at || ! $this->occurred_at) {
            return null;
        }

        return round($this->occurred_at->diffInMinutes(now()) / 60, 1);
    }

    /** Target response time in hours, from config. */
    public function slaHours(): int
    {
        return (int) config($this->is_urgent ? 'crm.sla.urgent_hours' : 'crm.sla.normal_hours');
    }

    public function isBreachingSla(): bool
    {
        return ($this->waitingHours() ?? 0) > $this->slaHours();
    }

    public function shortText(int $length = 90): string
    {
        return str($this->text ?? '')->squish()->limit($length)->toString();
    }
}
