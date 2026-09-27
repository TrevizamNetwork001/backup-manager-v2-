<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Rbac;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // STABILIZATION-1: login had no rate limiting at all (P1 finding).
        // Keyed by email+IP together (not just IP) so it can't be used to lock
        // out a legitimate account by spamming attempts from one address while
        // still slowing down a real credential-stuffing attempt.
        RateLimiter::for('login', function ($request) {
            $key = strtolower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key)->response(function () {
                return back()->withErrors(['email' => 'Muitas tentativas. Aguarde um minuto antes de tentar novamente.'])
                    ->onlyInput('email');
            });
        });
    }
}
