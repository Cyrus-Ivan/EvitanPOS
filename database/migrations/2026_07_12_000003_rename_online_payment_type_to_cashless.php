<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the payment type value "online" to "cashless" across existing rows.
 * "Online" was confusing (it read as an e-commerce/internet order); "cashless"
 * clearly means any non-cash tender (card, e-wallet, bank transfer).
 *
 * This is a one-time correction of an enum-like label, not an edit to any
 * price, quantity, total, or ledger entry — so it does not violate the
 * immutable-business-history invariant. The column stays a plain string with
 * no CHECK constraint; allowed values are enforced in FormRequests.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactions')
            ->where('payment_type', 'online')
            ->update(['payment_type' => 'cashless']);

        DB::table('sale_returns')
            ->where('refund_payment_type', 'online')
            ->update(['refund_payment_type' => 'cashless']);
    }

    public function down(): void
    {
        DB::table('transactions')
            ->where('payment_type', 'cashless')
            ->update(['payment_type' => 'online']);

        DB::table('sale_returns')
            ->where('refund_payment_type', 'cashless')
            ->update(['refund_payment_type' => 'online']);
    }
};
