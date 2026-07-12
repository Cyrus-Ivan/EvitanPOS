<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * After-sales event header — one row per return / defect. References
     * the original sale without ever mutating it (transactions and
     * transaction_items stay frozen). Append-only and immutable, matching
     * inventory_ledger. See docs/returns-refunds-voids-design.md.
     */
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Human-readable RET-YYYYMMDD-000001, sequence resets daily. See
            // SaleReturnNumberGenerator.
            $table->string('return_number')->unique();
            $table->foreignUuid('transaction_id')->constrained()->restrictOnDelete();
            // Allowed: return | defect
            $table->string('type');
            $table->text('reason')->nullable();
            // How money was returned: cash | online | others (null for defect / no-cash).
            $table->string('refund_payment_type')->nullable();
            // Convenience snapshot sum of line refund_amount — reports recompute
            // from the lines and never trust this stored total.
            $table->decimal('total_refund', 12, 2)->default(0);
            $table->foreignUuid('performed_by')->constrained('users')->restrictOnDelete();
            // Append-only / immutable: no updated_at (matches inventory_ledger).
            $table->timestamp('created_at')->useCurrent();

            $table->index('transaction_id');
            $table->index('type');
            $table->index('created_at');
            $table->index('return_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};
