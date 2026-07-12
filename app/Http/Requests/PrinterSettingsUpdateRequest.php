<?php

namespace App\Http\Requests;

use App\Support\Printing\PrinterSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrinterSettingsUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Authorization is handled by the route's role:owner middleware.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'receipt_paper_size' => ['required', Rule::in(PrinterSettings::PAPER_SIZES)],
            'receipt_connection' => ['required', Rule::in(PrinterSettings::CONNECTIONS)],
            'receipt_printer_name' => ['nullable', 'string', 'max:255', Rule::requiredIf(
                fn () => $this->input('receipt_connection') === PrinterSettings::CONNECTION_SYSTEM,
            )],
            'receipt_device_path' => ['nullable', 'string', 'max:255', Rule::requiredIf(
                fn () => in_array($this->input('receipt_connection'), [
                    PrinterSettings::CONNECTION_USB,
                    PrinterSettings::CONNECTION_BLUETOOTH,
                ], true),
            )],
            'receipt_network_host' => ['nullable', 'string', 'max:255', Rule::requiredIf(
                fn () => $this->input('receipt_connection') === PrinterSettings::CONNECTION_NETWORK,
            )],
            'receipt_network_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'auto_print' => ['required', 'boolean'],
            'open_cash_drawer' => ['required', 'boolean'],
        ];
    }

    /**
     * Normalise booleans to the '1'/'0' strings the settings table stores.
     *
     * @return array<string, mixed>
     */
    public function settingsPayload(): array
    {
        $data = $this->validated();

        $data['auto_print'] = $this->boolean('auto_print') ? '1' : '0';
        $data['open_cash_drawer'] = $this->boolean('open_cash_drawer') ? '1' : '0';

        return $data;
    }
}
