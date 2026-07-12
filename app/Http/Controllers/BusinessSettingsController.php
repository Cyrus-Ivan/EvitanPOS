<?php

namespace App\Http\Controllers;

use App\Http\Requests\BusinessSettingsUpdateRequest;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\SettingsDiff;
use App\Support\Printing\PrinterSettings;
use App\Support\Settings\BusinessSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BusinessSettingsController extends Controller
{
    public function __construct(
        private readonly BusinessSettings $settings,
        private readonly PrinterSettings $printerSettings,
    ) {}

    /**
     * Show the business settings page (business identity + receipt printer).
     */
    public function edit(): Response
    {
        return Inertia::render('business-settings', [
            'settings' => $this->settings->all(),
            'printerSettings' => $this->printerSettings->all(),
        ]);
    }

    /**
     * Update the business identity settings.
     */
    public function update(BusinessSettingsUpdateRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $before = $this->settings->all();
        $this->settings->update($request->validated());
        $diff = SettingsDiff::changed($before, $this->settings->all());

        if ($diff !== null) {
            $audit->record(
                action: 'settings.updated',
                description: 'Updated business settings: '.implode(', ', array_keys($diff['new'])).'.',
                old: $diff['old'],
                new: $diff['new'],
            );
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Business settings saved.',
        ]);
    }
}
