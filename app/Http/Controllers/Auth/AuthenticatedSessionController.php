<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\Auth\SessionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, SessionManager $sessions): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Record the login event.
        $sessions->handleLogin($request->user());

        // Stamp this run's boot id onto the session so the boot-session guard can
        // recognise it as belonging to the current run. A session that survives
        // into a later run (an unclean exit) will carry a stale id and be forced
        // out on its next request.
        $request->session()->put('boot_id', $sessions->currentBootId());

        // Straight to the password page for a forced change (UX shortcut —
        // RequirePasswordChange enforces it on every request regardless).
        if ($request->user()->must_change_password) {
            return redirect()->route('password.edit');
        }

        return redirect()->intended(route('pos', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, SessionManager $sessions): RedirectResponse
    {
        if ($user = $request->user()) {
            $sessions->handleLogout($user);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
