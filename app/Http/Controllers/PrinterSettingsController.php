<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrinterSettingsUpdateRequest;
use App\Support\Printing\PrinterException;
use App\Support\Printing\PrinterSettings;
use App\Support\Printing\ReceiptPrinter;
use Illuminate\Http\RedirectResponse;

/**
 * Receipt-printer configuration (Phase 11). Owner-only via the route's
 * role:owner middleware; the settings are surfaced on the business-settings
 * page alongside the business identity.
 */
class PrinterSettingsController extends Controller
{
    public function __construct(private readonly PrinterSettings $settings) {}

    /**
     * Persist the receipt-printer settings.
     */
    public function update(PrinterSettingsUpdateRequest $request): RedirectResponse
    {
        $this->settings->update($request->settingsPayload());

        // TODO(Phase 15): audit settings.updated

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Printer settings saved.',
        ]);
    }

    /**
     * Print a test slip to the configured printer so the owner can confirm the
     * hardware (and the cash drawer) works.
     */
    public function test(ReceiptPrinter $printer): RedirectResponse
    {
        try {
            $printed = $printer->test();
        } catch (PrinterException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        if (! $printed) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'No receipt printer is configured. Set one up above and save first.',
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Test receipt sent to the printer.',
        ]);
    }
}
