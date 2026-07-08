<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Auth\SessionManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Native\Laravel\Events\App\ApplicationBooted;
use Native\Laravel\Events\PowerMonitor\Shutdown;
use Native\Laravel\Events\Windows\WindowClosed;

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
        $this->registerAuthorizationGates();

        // Desktop lifecycle → login-history capture. These NativePHP events only
        // fire under the native runtime; outside it the listeners never run.
        Event::listen(ApplicationBooted::class, function (): void {
            app(SessionManager::class)->reconcile();
        });

        Event::listen(WindowClosed::class, function (): void {
            app(SessionManager::class)->handleWindowClosed();
        });

        Event::listen(Shutdown::class, function (): void {
            app(SessionManager::class)->handleShutdown();
        });
    }

    /**
     * Named abilities backing the Owner > Admin > Cashier permission matrix.
     * These are the single source of truth for both server-side authorization
     * and the resolved permission map shared to the frontend (see
     * App\Support\Auth\Permissions).
     */
    private function registerAuthorizationGates(): void
    {
        // Admin-level (also cleared by the Owner).
        Gate::define('access-reports', fn (User $user): bool => $user->hasRoleAtLeast(UserRole::Admin));
        Gate::define('access-audit-logs', fn (User $user): bool => $user->hasRoleAtLeast(UserRole::Admin));
        Gate::define('manage-users', fn (User $user): bool => $user->hasRoleAtLeast(UserRole::Admin));

        // Owner-only.
        Gate::define('access-settings', fn (User $user): bool => $user->isOwner());
        Gate::define('access-backup', fn (User $user): bool => $user->isOwner());
        Gate::define('view-item-cost', fn (User $user): bool => $user->isOwner());
    }
}
