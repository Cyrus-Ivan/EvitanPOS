<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Native\Laravel\Facades\App;

/**
 * Relaunch the desktop app (Phase 19). Offered after a database restore so
 * the once-per-launch provisioning (migrate + snapshot reconcile) runs
 * against the restored file. Only meaningful — and only routable — in the
 * native runtime; sits inside the Owner route group like the rest of backup.
 */
class AppRestartController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        abort_unless((bool) config('nativephp-internal.running'), 404);

        App::relaunch();

        // The relaunch tears the process down; this response is best-effort.
        return back();
    }
}
