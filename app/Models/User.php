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
        ];
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
