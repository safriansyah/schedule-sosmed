<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Content;
use App\Models\User;
use App\Policies\ContentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected array $policies = [
        Content::class => ContentPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Super Admin bypasses every check.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        // Turn each Permission case into a gate of the same name, so views can
        // simply do @can('content.create').
        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user) => $user->hasPermission($permission),
            );
        }
    }
}
