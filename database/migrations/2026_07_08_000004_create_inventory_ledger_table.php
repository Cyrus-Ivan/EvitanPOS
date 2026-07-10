<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The inventory ledger is append-only and immutable. Rows are never edited
     * or deleted; corrections are new compensating entries. Hence created_at
     * only (no updated_at, no soft delete).
     */
    public function up(): void
    {
        Schema::create('inventory_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('variant_id')->constrained()->restrictOnDelete();
            // Signed movement (+/-).
            $table->integer('quantity');
            // Running on-hand after this entry; never negative.
            $table->integer('resulting_snapshot');
            // Allowed: sale | stock_adjustment
            $table->string('type');
            // Allowed: sale | return | defect (null for stock adjustments)
            $table->string('sale_type')->nullable();
            $table->uuid('reference_id')->nullable();
            // Allowed: transaction | manual_adjustment | csv_import
            $table->string('reference_type');
            $table->text('notes')->nullable();
            $table->foreignUuid('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('variant_id');
            $table->index(['variant_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_ledger');
    }
};
