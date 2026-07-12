<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrinterSettingsUpdateRequest;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\SettingsDiff;
use App\Support\Printing\PrinterException;
use App\Support\Printing\PrinterSettings;
use App\Support\Printing\ReceiptPrinter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Native\Laravel\Facades\System;

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
    public function update(PrinterSettingsUpdateRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $before = $this->settings->all();
        $this->settings->update($request->settingsPayload());
        $diff = SettingsDiff::changed($before, $this->settings->all());

        if ($diff !== null) {
            $audit->record(
                action: 'settings.updated',
                description: 'Updated printer settings: '.implode(', ', array_keys($diff['new'])).'.',
                old: $diff['old'],
                new: $diff['new'],
            );
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Printer settings saved.',
        ]);
    }

    /**
     * List the OS print queues so the owner can pick one for a `system`
     * connection. Only works inside the NativePHP runtime (the Electron bridge
     * backs {@see System::printers()}); under `artisan serve` there is no bridge,
     * so this returns an empty list and the UI falls back to manual entry.
     *
     * @return JsonResponse<array{printers: list<array{name: string, label: string}>}>
     */
    public function printers(): JsonResponse
    {
        try {
            $printers = collect(System::printers())
                ->map(fn ($printer): array => [
                    'name' => (string) $printer->name,
                    'label' => (string) ($printer->displayName ?: $printer->name),
                ])
                ->filter(fn (array $p): bool => $p['name'] !== '')
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);
            $printers = [];
        }

        return response()->json(['printers' => $printers]);
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
