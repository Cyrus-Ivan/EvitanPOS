<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload validation for the inventory CSV import (Phase 7). Only the file
 * envelope is checked here — per-row content validation lives in
 * CsvInventoryImporter so it can produce user-friendly, row-attributed errors.
 * Authorization is handled by the route `role:admin` middleware, so no
 * authorize() override is needed (matches the app convention).
 */
class InventoryImportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // NativePHP desktop app: CSVs are exported from spreadsheets, so also
            // accept the text/plain mime some tools stamp on .csv files. 5 MB cap.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a CSV file to upload.',
            'file.mimes' => 'The file must be a CSV.',
            'file.max' => 'The file may not be larger than 5 MB.',
        ];
    }
}
