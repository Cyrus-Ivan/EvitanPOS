<?php

namespace App\Http\Requests\Sales;

use App\Models\Transaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An after-sales event (return / defect) against a completed sale.
 *
 * Authorization is layered: the route `auth` group admits any user (returns are
 * Cashier-level), and the controller elevates defect to Admin+. Money is never
 * accepted from the client — refunds are recomputed server-side from the captured
 * sale lines. The cumulative quantity quota (returned + defected ≤ sold) needs a
 * DB read, so it lives in the ProcessSaleReturn service, not here.
 */
class SaleReturnRequest extends FormRequest
{
    /**
     * Collapse a blank/whitespace-only reason to null so it never stores "".
     */
    protected function prepareForValidation(): void
    {
        $reason = trim((string) $this->input('reason', ''));

        $this->merge(['reason' => $reason === '' ? null : $reason]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isDefect = $this->input('type') === 'defect';

        /** @var Transaction $transaction */
        $transaction = $this->route('transaction');

        return [
            'type' => ['required', Rule::in(['return', 'defect'])],
            'reason' => ['nullable', 'string', 'max:500'],

            // Money is returned for returns, not for defects.
            'refund_payment_type' => [
                Rule::requiredIf(fn (): bool => ! $isDefect),
                'nullable',
                Rule::in(['cash', 'online', 'others']),
            ],

            // Every event names the lines and quantities it acts on.
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.transaction_item_id' => [
                'required',
                'uuid',
                // Must be a line of THIS transaction.
                Rule::exists('transaction_items', 'id')->where('transaction_id', $transaction->getKey()),
            ],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999999'],
        ];
    }
}
