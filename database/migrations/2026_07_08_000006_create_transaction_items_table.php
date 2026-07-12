<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sale lines only. Prices and costs are snapshotted onto each row at time of
     * sale and never change. Append-only: after-sales activity (returns, voids,
     * defects) lives in the separate sale_returns family and references these
     * rows without mutating them — see docs/returns-refunds-voids-design.md.
     */
    public function up(): void
    {
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->constrained()->restrictOnDelete();
            $table->integer('quantity');
            // Snapshots captured at time of sale.
            $table->decimal('selling_price', 12, 2);
            $table->decimal('cost', 12, 2)->nullable();
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            // Append-only sale line: immutable, so no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index('transaction_id');
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
    }
};
