<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Serves as both the internal transaction id and the customer-facing
            // receipt/invoice number. A continuous, gapless, zero-padded serial
            // that never resets — the shape a BIR-registered CRM/POS serial takes
            // (unique, consecutive, non-resetting). See TransactionNumberGenerator.
            $table->string('transaction_number')->unique();
            // Allowed: cash | online | others
            $table->string('payment_type');
            $table->decimal('discount', 12, 2)->default(0);
            // Allowed: amount | percentage (null when no discount)
            $table->string('discount_type')->nullable();
            $table->decimal('total', 12, 2);
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change', 12, 2)->nullable();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            // Append-only: a completed sale is immutable business history, so
            // there is no updated_at (matches inventory_ledger / audit_logs).
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
