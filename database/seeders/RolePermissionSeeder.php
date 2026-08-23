<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /** Authority ranking — used for sorting and "who outranks whom" checks. */
    private const LEVELS = [
        'super_admin' => 100,
        'director' => 90,
        'curator' => 60,
        'verifier' => 50,
        'creative' => 30,
    ];

    public function run(): void
    {
        // 1. Every permission defined by the enum.
        foreach (PermissionEnum::cases() as $permission) {
            Permission::updateOrCreate(
                ['key' => $permission->value],
                ['label' => $permission->label(), 'group' => $permission->group()],
            );
        }

        $permissionIds = Permission::pluck('id', 'key');

        // 2. Roles + their capability matrix.
        foreach (RoleName::cases() as $roleName) {
            $role = Role::updateOrCreate(
                ['name' => $roleName->value],
                [
                    'label' => $roleName->label(),
                    'description' => $roleName->description(),
                    'level' => self::LEVELS[$roleName->value] ?? 0,
                ],
            );

            $ids = collect($roleName->permissions())
                ->map(fn (PermissionEnum $p) => $permissionIds[$p->value] ?? null)
                ->filter()
                ->all();

            $role->permissions()->sync($ids);
        }
    }
}
