<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class AboutController extends Controller
{
    /**
     * The app's version surface (Phase 19): name, release version and
     * copyright, sourced from config/nativephp.php — the same values stamped
     * onto the packaged build and its installer artifacts.
     */
    public function show(): Response
    {
        return Inertia::render('settings/about', [
            'appName' => config('app.name'),
            'version' => config('nativephp.version'),
            'copyright' => config('nativephp.copyright'),
        ]);
    }
}
