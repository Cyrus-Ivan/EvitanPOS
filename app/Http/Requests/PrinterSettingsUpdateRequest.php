<?php

namespace App\Http\Requests;

use App\Support\Printing\PrinterSettingsRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
        return PrinterSettingsRules::rules($this);
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
