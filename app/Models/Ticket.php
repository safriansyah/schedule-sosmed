<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\Priority;
use App\Enums\TicketFlag;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Services\Students\StudentStats;
use App\Services\Tickets\TicketNumberFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * One case that needs following up.
 *
 * Follow-ups hang off this through the shared polymorphic `follow_ups` table,
 * so a ticket has many of them and none is ever overwritten by the next.
 */
class Ticket extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'number', 'source', 'subject', 'description',
        'category_id', 'sub_category_id', 'status', 'priority', 'flag',
        'student_id', 'contact_id',
        'requester_name', 'requester_nim', 'requester_nac', 'requester_phone', 'requester_email',
        'interaction_id', 'source_channel', 'source_external_id', 'source_username', 'source_sender_id',
        'source_text', 'source_post_id', 'source_post_url', 'source_created_at',
        'assigned_to', 'assigned_at', 'created_by',
        'first_response_at', 'last_follow_up_at', 'follow_up_count', 'due_at',
        'resolution_note', 'closed_by', 'closed_at', 'extra',
        'attachment_path', 'attachment_name',
    ];

    protected function casts(): array
    {
        return [
            'source' => TicketSource::class,
            'status' => TicketStatus::class,
            'priority' => Priority::class,
            'flag' => TicketFlag::class,
            'source_created_at' => 'datetime',
            'assigned_at' => 'datetime',
            'first_response_at' => 'datetime',
            'last_follow_up_at' => 'datetime',
            'due_at' => 'datetime',
            'closed_at' => 'datetime',
            'extra' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $ticket) {
            $ticket->number ??= self::nextNumber();
        });
    }

    /* -----------------------------------------------------------------
     | Relations
     * ----------------------------------------------------------------- */

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'sub_category_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** The comment/DM this ticket grew out of. Null for manual tickets. */
    public function interaction(): BelongsTo
    {
        return $this->belongsTo(Interaction::class);
    }

    /** The queue entry this ticket was made from (source Buku Tamu). */
    public function guestBookEntry(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(GuestBookEntry::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TicketAssignment::class)->latest();
    }

    /** Oldest first: the history reads top-down, "Follow Up #1" first. */
    public function followUps(): MorphMany
    {
        return $this->morphMany(FollowUp::class, 'followupable')->oldest();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** TiketDetail — the students this case turned out to be about. */
    public function details(): HasMany
    {
        return $this->hasMany(TicketDetail::class)->orderBy('nim');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest();
    }

    /* -----------------------------------------------------------------
     | Scopes
     * ----------------------------------------------------------------- */

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value]);
    }

    /** Same rule as students: no "see everything" permission, no other queues. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasPermission(Permission::ViewAllTickets)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('assigned_to', $user->id)->orWhere('created_by', $user->id);
        });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('number', 'like', $like)
                ->orWhere('subject', 'like', $like)
                ->orWhere('requester_name', 'like', $like)
                ->orWhere('requester_nim', 'like', $like)
                ->orWhere('requester_phone', 'like', $like)
                ->orWhere('source_username', 'like', $like)
                // A ticket is very often looked up by the NIM an operator
                // recorded on it, which lives on ticket_details.
                ->orWhereHas('details', fn (Builder $d) => $d->where('nim', 'like', $like));
        });
    }

    /** @param array<string, mixed> $filters */
    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        return $query
            ->search($filters['q'] ?? null)
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['priority'] ?? null), fn ($q) => $q->where('priority', $filters['priority']))
            ->when(filled($filters['source'] ?? null), fn ($q) => $q->where('source', $filters['source']))
            ->when(filled($filters['flag'] ?? null), fn ($q) => $q->where('flag', $filters['flag']))
            ->when(filled($filters['category'] ?? null), fn ($q) => $q->where(function (Builder $c) use ($filters) {
                // A parent category should also match tickets filed against one
                // of its sub-categories.
                $c->where('category_id', $filters['category'])
                    ->orWhere('sub_category_id', $filters['category']);
            }))
            ->when(($filters['operator'] ?? '') !== '', function (Builder $q) use ($filters) {
                return (string) $filters['operator'] === '0'
                    ? $q->whereNull('assigned_to')
                    : $q->where('assigned_to', (int) $filters['operator']);
            })
            ->regionOf($filters);
    }

    /**
     * Narrow to tickets whose student lives in a region.
     *
     * Only tickets raised from the student import have a region at all — a
     * ticket born from an Instagram comment has a commenter, not an address —
     * so the ticket list only offers these filters once the source filter is
     * set to Import Mahasiswa. Applying one anyway is not an error: it simply
     * excludes every ticket without a student, which is what was asked for.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeRegionOf(Builder $query, array $filters): Builder
    {
        $region = [];

        foreach (StudentStats::REGION_LEVELS as $level) {
            if (filled($filters[$level] ?? null)) {
                $region[$level] = $filters[$level];
            }
        }

        if ($region === []) {
            return $query;
        }

        // EXISTS rather than a join: a join would multiply rows if a ticket
        // ever gained a second student link, and silently inflate the count
        // shown above the list.
        return $query->whereHas('student', function (Builder $student) use ($region) {
            foreach ($region as $level => $value) {
                $student->where($level, $value);
            }
        });
    }

    /* -----------------------------------------------------------------
     | Helpers
     * ----------------------------------------------------------------- */

    /**
     * Next ticket number, following the pattern an admin configured.
     *
     * The pattern itself lives in settings (Ticketing → Format ID Tiket);
     * `TicketNumberFormatter` turns it into a value. Read-then-insert races
     * under concurrency, so the column is UNIQUE and the caller retries — see
     * createWithNumber().
     */
    public static function nextNumber(?string $format = null): string
    {
        return app(TicketNumberFormatter::class)->next($format);
    }

    /**
     * Create, retrying if two requests grabbed the same number. The unique
     * index is what actually guarantees uniqueness; this turns the collision
     * into a second attempt instead of a 500.
     *
     * @param  array<string, mixed>  $attributes
     * @param  string|null  $format  which numbering format to use; null = the default one
     */
    public static function createWithNumber(array $attributes, ?string $format = null): self
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return static::create($attributes + ['number' => static::nextNumber($format)]);
            } catch (QueryException $e) {
                // 23000 = integrity constraint violation. Anything else is a
                // real error and must not be swallowed.
                if ($e->getCode() !== '23000' || ! str_contains($e->getMessage(), 'number')) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Tidak bisa membuat nomor tiket unik setelah 5 percobaan.');
    }

    public function isClosed(): bool
    {
        return $this->status === TicketStatus::Closed;
    }

    /** Closed tickets stay readable but nothing may be added to them. */
    public function isEditable(): bool
    {
        return ! $this->isClosed();
    }

    public function requesterLabel(): string
    {
        return $this->requester_name
            ?: ($this->source_username ? '@'.$this->source_username : 'Tanpa nama');
    }

    public function isLead(): bool
    {
        return $this->flag === TicketFlag::Lead;
    }

    public function hasAttachment(): bool
    {
        return filled($this->attachment_path);
    }

    /** True when the ticket carries a social-media origin worth rendering. */
    public function hasSourceReference(): bool
    {
        return filled($this->source_username) || filled($this->source_text) || filled($this->source_post_url);
    }
}
