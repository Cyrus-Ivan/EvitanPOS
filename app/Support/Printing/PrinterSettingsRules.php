<?php

namespace App\Support\Printing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The one validation rule set for printer settings, shared by the Business
 * Settings printer form and the initial setup wizard so the conditional
 * per-connection requirements can never drift apart.
 */
class PrinterSettingsRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function rules(FormRequest $request): array
    {
        return [
            'receipt_paper_size' => ['required', Rule::in(PrinterSettings::PAPER_SIZES)],
            'receipt_connection' => ['required', Rule::in(PrinterSettings::CONNECTIONS)],
            'receipt_printer_name' => ['nullable', 'string', 'max:255', Rule::requiredIf(
                fn () => $request->input('receipt_connection') === PrinterSettings::CONNECTION_SYSTEM,
            )],
            'receipt_device_path' => ['nullable', 'string', 'max:255', Rule::requiredIf(
                fn () => in_array($request->input('receipt_connection'), [
                    PrinterSettings::CONNECTION_USB,
                    PrinterSettings::CONNECTION_BLUETOOTH,
                ], true),
            )],
            'receipt_network_host' => ['nullable', 'string', 'max:255', Rule::requiredIf(
                fn () => $request->input('receipt_connection') === PrinterSettings::CONNECTION_NETWORK,
            )],
            'receipt_network_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'auto_print' => ['required', 'boolean'],
            'open_cash_drawer' => ['required', 'boolean'],
        ];
    }
}
