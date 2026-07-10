<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Denormalized current-stock cache — one row per variant, updated on every
     * inventory_ledger insert. This is the ONLY mutable stock store (the ledger
     * itself is append-only). Current stock reads come from here; it is
     * reconcilable against the ledger sum at any time. See CLAUDE.md:
     * "Current stock = ledger sum reconciled against a stock snapshot".
     */
    public function up(): void
    {
        Schema::create('stock_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('variant_id')->unique()->constrained()->restrictOnDelete();
            // Current on-hand quantity; never negative (enforced at write time).
            $table->integer('quantity')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_snapshots');
    }
};
