<?php

namespace App\Providers;

use App\Models\User;
use App\Services\InstanceTimezone;
use App\Support\Rbac;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\ScheduleInterruptCommand;
use Illuminate\Console\Scheduling\SchedulePauseCommand;
use Illuminate\Console\Scheduling\ScheduleResumeCommand;
use Illuminate\Console\Scheduling\ScheduleRunCommand;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton so InstanceTimezone::get()'s per-instance cache (see that
        // class) is shared across the whole request instead of a fresh
        // (fresh-query) instance on every resolution — that was the actual
        // N+1. In production this is safe because each php-fpm request boots
        // a brand new container; a test that simulates two separate requests
        // in one test method must explicitly forget the bound instance
        // between them (see DashboardTimezoneTest) — scoped() would only do
        // that automatically under Octane, which this app doesn't use.
        $this->app->singleton(InstanceTimezone::class);
        $this->app->when([
            ScheduleRunCommand::class, SchedulePauseCommand::class,
            ScheduleResumeCommand::class, ScheduleInterruptCommand::class,
        ])->needs(Repository::class)->give(fn () => $this->app->make('cache')->store('database'));
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
