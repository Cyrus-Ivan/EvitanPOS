<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes a fresh install to the initial setup wizard, and keeps the wizard
 * unreachable once any user exists. The fresh-install signal is the absence of
 * any user, archived ones included — a restored backup or an upgraded install
 * always has users, so neither ever re-triggers the wizard. Checked live on
 * every request (one indexed EXISTS on local SQLite): no persisted flag, so
 * the answer can never drift from the database.
 *
 * Runs first in the web group — before the boot-session and password-change
 * guards, neither of which can apply pre-auth on an empty database.
 */
class RequireInitialSetup
{
    /** Route names that make up the setup wizard. */
    private const SETUP_ROUTES = ['setup', 'setup.store', 'setup.printers'];

    public function handle(Request $request, Closure $next): Response
    {
        $isSetupRoute = $request->routeIs(...self::SETUP_ROUTES);

        if (User::withTrashed()->doesntExist()) {
            return $isSetupRoute ? $next($request) : redirect()->route('setup');
        }

        return $isSetupRoute ? redirect()->route('login') : $next($request);
    }
}
