<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Rbac;
use Illuminate\Support\Facades\Gate;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('pagination.default');

        foreach (Rbac::PERMISSIONS as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
