<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lines of an after-sales event. Each references the original sold line
     * (transaction_item) and snapshots its selling_price / cost — returns always
     * use the captured price, never the current variant price. Whether stock is
     * restored is derived from sale_returns.type (return restores, defect does
     * not), so it is not stored here. See docs/returns-refunds-voids-design.md.
     */
    public function up(): void
    {
        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transaction_item_id')->constrained()->restrictOnDelete();
            // Denormalized (mirrors transaction_items) for ledger + reporting convenience.
            $table->foreignUuid('variant_id')->constrained()->restrictOnDelete();
            $table->integer('quantity');
            // Snapshots copied from the source transaction_item.
            $table->decimal('selling_price', 12, 2);
            $table->decimal('cost', 12, 2)->nullable();
            // Money reversed for this line (discount-correct; see the design doc).
            $table->decimal('refund_amount', 12, 2);
            // Append-only line: immutable, so no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index('sale_return_id');
            $table->index('transaction_item_id');
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
    }
};
