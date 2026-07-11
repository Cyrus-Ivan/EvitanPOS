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
            // Human-readable, format YYYYMMDD-HHMMSS-000001, sequence resets daily.
            $table->string('transaction_number')->unique();
            // Receipt number equals the transaction number.
            $table->string('receipt_number');
            // Allowed: cash | online | others
            $table->string('payment_type');
            $table->decimal('discount', 12, 2)->default(0);
            // Allowed: amount | percentage (null when no discount)
            $table->string('discount_type')->nullable();
            $table->decimal('total', 12, 2);
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change', 12, 2)->nullable();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index('receipt_number');
            $table->index('created_at');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
