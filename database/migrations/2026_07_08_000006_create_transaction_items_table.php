<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prices and costs are snapshotted onto each row at time of sale and never
     * change. Returns and defects are recorded as additional transaction items
     * linked back to the original sold line.
     */
    public function up(): void
    {
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->constrained()->restrictOnDelete();
            // Allowed: sale | return | defect
            $table->string('line_type')->default('sale');
            $table->integer('quantity');
            // Snapshots captured at time of sale.
            $table->decimal('selling_price', 12, 2);
            $table->decimal('cost', 12, 2)->nullable();
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            // Links a return/defect line to its original sold line.
            $table->foreignUuid('source_transaction_item_id')
                ->nullable()
                ->constrained('transaction_items')
                ->restrictOnDelete();
            $table->timestamps();

            $table->index('transaction_id');
            $table->index('variant_id');
            $table->index('source_transaction_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
    }
};
