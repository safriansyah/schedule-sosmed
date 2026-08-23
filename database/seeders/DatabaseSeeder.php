<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /** Team members created for each role (per the agreed org structure). */
    private const TEAM = [
        RoleName::SuperAdmin->value => [
            ['Super Admin', 'admin@example.com'],
        ],
        RoleName::Director->value => [
            ['Direktur', 'direktur@example.com'],
        ],
        RoleName::Curator->value => [
            ['Curator Satu', 'curator1@example.com'],
            ['Curator Dua', 'curator2@example.com'],
        ],
        RoleName::Verifier->value => [
            ['Verifikator Satu', 'verifikator1@example.com'],
            ['Verifikator Dua', 'verifikator2@example.com'],
        ],
        RoleName::Creative->value => [
            ['Creative Satu', 'creative1@example.com'],
            ['Creative Dua', 'creative2@example.com'],
            ['Creative Tiga', 'creative3@example.com'],
        ],
    ];

    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $roles = Role::pluck('id', 'name');

        foreach (self::TEAM as $roleName => $members) {
            foreach ($members as [$name, $email]) {
                $user = User::firstOrNew(['email' => $email]);

                $user->fill(['name' => $name, 'password' => Hash::make('password')]);

                // role_id and is_active are guarded against mass assignment,
                // so they are set explicitly here.
                $user->role_id = $roles[$roleName] ?? null;
                $user->is_active = true;

                $user->save();
            }
        }
    }
}
