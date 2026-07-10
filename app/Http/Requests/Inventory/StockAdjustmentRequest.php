<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual stock adjustment. Authorization is handled by the route `role:admin`
 * middleware (matches the app convention), so no authorize() override.
 *
 * Two modes:
 *  - `delta` (default) → `value` is the signed change to apply (+add / −remove).
 *  - `set`   → `value` is the true counted on-hand; the controller derives the
 *              signed ledger delta (value − current stock). Requires value ≥ 0.
 *
 * Notes are optional except when setting a count or removing stock (a negative
 * delta) — both need an explanation captured on the ledger row.
 */
class StockAdjustmentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isDelta = $this->input('mode') === 'delta';

        return [
            'mode' => ['required', 'string', 'in:set,delta'],
            // 'set' is an absolute count (≥ 0); 'delta' is a signed change where a
            // positive value adds stock and a negative removes it — zero is not a
            // change and is rejected here rather than silently no-op'd.
            'value' => [
                'required',
                'integer',
                $isDelta ? 'min:-999999' : 'min:0',
                'max:999999',
                ...($isDelta ? ['not_in:0'] : []),
            ],
            'notes' => [
                Rule::requiredIf(fn (): bool => $this->input('mode') === 'set' || (int) $this->input('value') < 0),
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.not_in' => 'Enter a positive number to add stock or a negative number to remove it.',
        ];
    }
}
