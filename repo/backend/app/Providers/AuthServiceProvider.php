<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(function (User $user): bool|null {
            return $user->hasRole('system_admin') ? true : null;
        });

        Gate::define('permission', function (User $user, string $permission): bool {
            $user->loadMissing(['roles.permissions', 'permissions']);

            return $user->hasPermission($permission);
        });
    }
}
