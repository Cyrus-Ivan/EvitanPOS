<?php

namespace App\Providers;

use App\Support\Auth\SessionManager;
use Illuminate\Support\Facades\Event;
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
}
