<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Gate a route behind a minimum role. Honours the Owner > Admin > Cashier
     * hierarchy, so `role:admin` also admits the Owner.
     *
     * @param  string  $role  The minimum role required (owner|admin|cashier).
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRoleAtLeast(UserRole::from($role))) {
            abort(403);
        }

        return $next($request);
    }
}
