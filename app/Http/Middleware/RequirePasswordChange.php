<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confines a user whose password is flagged for a forced change (the seeded
 * first-launch Owner, or anyone after an administrative reset) to the
 * password-change page until they set their own password. Enforcement lives
 * here; the login redirect to the same page is only a UX shortcut.
 */
class RequirePasswordChange
{
    /** Routes a flagged user may still reach. */
    private const ALLOWED_ROUTES = ['password.edit', 'password.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null
            && $user->must_change_password
            && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return redirect()->route('password.edit');
        }

        return $next($request);
    }
}
