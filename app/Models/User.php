<?php

namespace App\Models;

use App\Enums\Permission as PermissionEnum;
use App\Enums\RoleName;
use App\Support\PasswordInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Deliberately excludes `role_id`, `is_active` and `uuid`: those decide
     * privilege and identity, so they must be set explicitly in code — never
     * from request data.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'email', 'phone', 'password', 'avatar_path',
    ];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            // NOT the 'hashed' cast. See the password() mutator below: the
            // cast rejects $2a$/$2b$ hashes and would hash them a second time.
            'password' => 'string',
            'is_active' => 'boolean',
            'login_schedule_enabled' => 'boolean',
            'login_start_date' => 'date:Y-m-d',
            'login_end_date' => 'date:Y-m-d',
        ];
    }

    /** Timezone the login schedule is written and checked in. */
    public const LOGIN_SCHEDULE_TZ = 'Asia/Jakarta';

    /**
     * May this account log in (or stay logged in) at $at?
     *
     * Without an enabled schedule: always. With one, every bound that is set
     * must hold — the date range inclusive, and the daily hours inclusive. A
     * window like 22:00–06:00 is read as running past midnight.
     */
    public function loginAllowedAt(?\DateTimeInterface $at = null): bool
    {
        if (! $this->login_schedule_enabled) {
            return true;
        }

        $now = \Illuminate\Support\Carbon::instance($at ?? now())->setTimezone(self::LOGIN_SCHEDULE_TZ);
        $today = $now->toDateString();

        if ($this->login_start_date && $today < $this->login_start_date->toDateString()) {
            return false;
        }

        if ($this->login_end_date && $today > $this->login_end_date->toDateString()) {
            return false;
        }

        $time = $now->format('H:i:s');
        $start = $this->login_start_time ? substr($this->login_start_time, 0, 8) : null;
        $end = $this->login_end_time ? substr($this->login_end_time, 0, 8) : null;

        return match (true) {
            $start && $end && $start <= $end => $time >= $start && $time <= $end,
            $start && $end => $time >= $start || $time <= $end,
            (bool) $start => $time >= $start,
            (bool) $end => $time <= $end,
            default => true,
        };
    }

    /** "05-10-2026 s/d 10-10-2026 · 08:00–17:00 WIB", or null when off. */
    public function loginScheduleLabel(): ?string
    {
        if (! $this->login_schedule_enabled) {
            return null;
        }

        $dates = match (true) {
            $this->login_start_date && $this->login_end_date => $this->login_start_date->format('d-m-Y').' s/d '.$this->login_end_date->format('d-m-Y'),
            (bool) $this->login_start_date => 'mulai '.$this->login_start_date->format('d-m-Y'),
            (bool) $this->login_end_date => 'sampai '.$this->login_end_date->format('d-m-Y'),
            default => 'setiap hari',
        };

        $hours = $this->login_start_time || $this->login_end_time
            ? ' · '.substr($this->login_start_time ?? '00:00', 0, 5).'–'.substr($this->login_end_time ?? '23:59', 0, 5).' WIB'
            : '';

        return $dates.$hours;
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Hash a plain password, or take one that was already hashed elsewhere.
     *
     * Replaces Laravel's `hashed` cast so that BOTH paths work: a typed
     * password, and a bcrypt hash pasted from an outside generator. The cast
     * alone could not do the second — it only recognises $2y$, so the $2a$ and
     * $2b$ hashes those generators emit were hashed a second time and the
     * account ended up with a password nobody knew.
     *
     * Living on the model rather than in a controller is the point: tinker,
     * the seeders and the Pengguna form all set this attribute, and all three
     * have to behave the same.
     */
    protected function password(): Attribute
    {
        return Attribute::set(function (?string $value) {
            if ($value === null || $value === '') {
                return $value;
            }

            return PasswordInput::asStorableHash($value) ?? Hash::make($value);
        });
    }

    /* -----------------------------------------------------------------
     | Relations
     * ----------------------------------------------------------------- */

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function contents(): HasMany
    {
        return $this->hasMany(Content::class, 'created_by');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /* -----------------------------------------------------------------
     | Roles & permissions
     * ----------------------------------------------------------------- */

    public function hasRole(RoleName ...$roles): bool
    {
        return $this->role !== null && in_array($this->role->name, $roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleName::SuperAdmin);
    }

    public function isDirector(): bool
    {
        return $this->hasRole(RoleName::Director);
    }

    public function isCreative(): bool
    {
        return $this->hasRole(RoleName::Creative);
    }

    public function isCurator(): bool
    {
        return $this->hasRole(RoleName::Curator);
    }

    public function isVerifier(): bool
    {
        return $this->hasRole(RoleName::Verifier);
    }

    /** Read-only roles may never mutate content. */
    public function isReadOnly(): bool
    {
        return $this->role?->name->isReadOnly() ?? false;
    }

    public function hasPermission(PermissionEnum|string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->role?->hasPermission($permission) ?? false;
    }

    /* -----------------------------------------------------------------
     | Scopes & helpers
     * ----------------------------------------------------------------- */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Roles a ticket — or a student, whose tickets follow them — may be handed
     * to, in the order the operator picker groups them. ONE list, used by the
     * ticket assign form, the student hand-out on /students/unsigned and the
     * validation behind both, so the two screens cannot drift apart.
     */
    public const TICKET_HANDLER_ROLES = [
        RoleName::FollowUp, RoleName::Operator, RoleName::Pic, RoleName::Manager, RoleName::SuperAdmin,
    ];

    /** Active accounts in one of TICKET_HANDLER_ROLES. */
    public function scopeTicketHandlers(Builder $query): Builder
    {
        $roles = array_map(fn (RoleName $r) => $r->value, self::TICKET_HANDLER_ROLES);

        return $query->active()->whereHas('role', fn ($q) => $q->whereIn('name', $roles));
    }

    /**
     * The operator picker's options: grouped by role (in TICKET_HANDLER_ROLES
     * order), then by name.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function ticketHandlerOptions()
    {
        $roles = array_map(fn (RoleName $r) => $r->value, self::TICKET_HANDLER_ROLES);

        return static::query()
            ->ticketHandlers()
            ->with('role:id,name,label')
            ->orderBy('name')
            ->get(['id', 'name', 'role_id'])
            ->sortBy(fn (self $u) => array_search($u->role?->name?->value, $roles, true))
            ->values();
    }

    /** Validation rule: the id must be someone ticketHandlers() would list. */
    public static function ticketHandlerRule(): \Illuminate\Validation\Rules\Exists
    {
        return \Illuminate\Validation\Rule::exists('users', 'id')->where(function ($q) {
            $q->where('is_active', true)
                ->whereNull('deleted_at')
                ->whereIn('role_id', Role::whereIn('name', array_map(fn (RoleName $r) => $r->value, self::TICKET_HANDLER_ROLES))->select('id'));
        });
    }

    /** Named `withRole` (not `role`) to avoid clashing with the role() relation. */
    public function scopeWithRole(Builder $query, RoleName $role): Builder
    {
        return $query->whereHas('role', fn (Builder $q) => $q->where('name', $role->value));
    }

    /** First letter of the name — used for avatar placeholders. */
    public function initial(): string
    {
        return strtoupper(mb_substr($this->name ?? '?', 0, 1));
    }

    public function roleLabel(): string
    {
        return $this->role?->label ?? 'Tanpa Role';
    }
}
