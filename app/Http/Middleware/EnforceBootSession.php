<?php

namespace App\Http\Middleware;

use App\Support\Auth\SessionManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces out any authenticated session that outlived the application run it was
 * created in.
 *
 * NativePHP stores sessions as files that survive an unclean exit (window
 * closed, OS shutdown, crash, or force-kill), so on the next launch a carried
 * over session would otherwise stay logged in. Each run mints a fresh boot id
 * (see SessionManager::handleBoot); a session whose stamped id no longer matches
 * the current run is treated as an unclean exit — recorded and signed out.
 *
 * Fails open when no boot id has been minted (e.g. outside the native runtime),
 * so it never locks anyone out of the plain web/test environment.
 */
class EnforceBootSession
{
    public function __construct(private readonly SessionManager $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $currentBootId = $this->sessions->currentBootId();

        if ($user !== null
            && $currentBootId !== null
            && $request->session()->get('boot_id') !== $currentBootId) {
            $this->sessions->markUncleanExit($user);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // invalidate() opened a fresh session, so the flash lands there
            // and the login page can explain the sign-out.
            return redirect()->route('login')
                ->with('status', 'You were signed out because the app restarted. Please log in again.');
        }

        return $next($request);
    }
}
