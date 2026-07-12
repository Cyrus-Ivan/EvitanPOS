<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Auth\SessionManager;
use App\Support\Backup\DirectoryPicker;
use App\Support\Backup\NativeDialogPicker;
use App\Support\Search\LikeQuery;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Native\Laravel\Events\App\ApplicationBooted;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DirectoryPicker::class, NativeDialogPicker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAuthorizationGates();

        // Wildcard-safe LIKE search macros (whereLike / orWhereLike / whereLikeAny).
        LikeQuery::registerMacros();

        // Mint a fresh boot id on every launch. A session stamped with an older
        // id (see EnforceBootSession) outlived the run that created it — i.e. the
        // previous run ended without a clean logout. ApplicationBooted fires only
        // under the native runtime; outside it the listener never runs (and the
        // guard fails open when no boot id has been minted).
        Event::listen(ApplicationBooted::class, function (): void {
            app(SessionManager::class)->handleBoot();
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
