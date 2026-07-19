<?php

namespace App\Http\Requests\Setup;

use App\Support\Printing\PrinterSettingsRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The initial setup wizard's single atomic submit: Owner account, business
 * details, localization, and (optionally) printer configuration. No
 * authorization — it only runs pre-auth on a fresh install, gated by the
 * RequireInitialSetup middleware.
 */
class CompleteSetupRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Owner account (mirrors StoreUserRequest; role is fixed to Owner).
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'password' => ['required', 'confirmed', Password::defaults()],

            // Business identity (mirrors BusinessSettingsUpdateRequest).
            'business_name' => ['required', 'string', 'max:255'],
            'business_address' => ['nullable', 'string', 'max:1000'],
            'tin' => ['nullable', 'string', 'max:50'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', 'timezone:all'],
            'currency_symbol' => ['required', 'string', 'max:5'],

            // Printer step is skippable; its fields only apply when configured.
            'configure_printer' => ['required', 'boolean'],
            ...($this->boolean('configure_printer') ? PrinterSettingsRules::rules($this) : []),
        ];
    }

    /**
     * The business-settings payload to persist.
     *
     * @return array<string, string>
     */
    public function businessPayload(): array
    {
        $data = $this->validated();

        return [
            'business_name' => (string) $data['business_name'],
            'business_address' => (string) ($data['business_address'] ?? ''),
            'tin' => (string) ($data['tin'] ?? ''),
            'receipt_footer' => (string) ($data['receipt_footer'] ?? ''),
            'timezone' => (string) $data['timezone'],
            'currency_symbol' => (string) $data['currency_symbol'],
        ];
    }

    /**
     * The printer-settings payload, with booleans normalised to the '1'/'0'
     * strings the settings table stores. Only valid when configure_printer.
     *
     * @return array<string, mixed>
     */
    public function printerPayload(): array
    {
        $data = $this->validated();

        $data['auto_print'] = $this->boolean('auto_print') ? '1' : '0';
        $data['open_cash_drawer'] = $this->boolean('open_cash_drawer') ? '1' : '0';

        return collect($data)->only([
            'receipt_paper_size',
            'receipt_connection',
            'receipt_printer_name',
            'receipt_device_path',
            'receipt_network_host',
            'receipt_network_port',
            'auto_print',
            'open_cash_drawer',
        ])->all();
    }
}
