<?php

namespace App\Http\Controllers;

use App\Http\Requests\BusinessSettingsUpdateRequest;
use App\Support\Settings\BusinessSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BusinessSettingsController extends Controller
{
    public function __construct(private readonly BusinessSettings $settings) {}

    /**
     * Show the business settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('business-settings', [
            'settings' => $this->settings->all(),
        ]);
    }

    /**
     * Update the business identity settings.
     */
    public function update(BusinessSettingsUpdateRequest $request): RedirectResponse
    {
        $this->settings->update($request->validated());

        // TODO(Phase 15): audit settings.updated

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Business settings saved.',
        ]);
    }
}
