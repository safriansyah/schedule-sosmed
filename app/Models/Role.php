<?php

namespace App\Models;

use App\Enums\Permission as PermissionEnum;
use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['name', 'label', 'description', 'level'];

    protected function casts(): array
    {
        return [
            'name' => RoleName::class,
            'level' => 'integer',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isRole(RoleName $role): bool
    {
        return $this->name === $role;
    }

    public function hasPermission(PermissionEnum|string $permission): bool
    {
        $key = $permission instanceof PermissionEnum ? $permission->value : $permission;

        return $this->permissions->contains('key', $key);
    }
}
