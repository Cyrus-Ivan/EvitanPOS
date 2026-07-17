<?php

use App\Http\Middleware\EnforceBootSession;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            // Runs after StartSession and before Inertia so a stale-boot session
            // is signed out (and short-circuited to login) before any page props
            // are shared. Self-guards on an authenticated user.
            EnforceBootSession::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            // Raw JSON endpoints (e.g. pos.lookup) keep their JSON error shape.
            // Inertia visits request text/html, so they fall through to the
            // mappings below.
            if ($request->expectsJson()) {
                return $response;
            }

            // A stale CSRF token must never dead-end the desktop app: bounce
            // back with a toast; auth middleware handles the logged-out case.
            if ($status === 419) {
                return back()->with('toast', [
                    'type' => 'error',
                    'message' => 'Your session expired. Please try again.',
                ]);
            }

            if (! config('app.debug') && in_array($status, [403, 404, 500, 503], true)) {
                return Inertia::render('error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
