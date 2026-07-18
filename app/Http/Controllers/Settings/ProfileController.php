<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only profile view. Identity is managed through User Management
 * (Phase 13): names are immutable after creation, users are archived rather
 * than deleted, and every identity change is audited there — so this
 * controller deliberately has no update or destroy action (F-15).
 */
class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile');
    }
}
