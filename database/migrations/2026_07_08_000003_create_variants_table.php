<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->string('name');
            // SKU / barcode are optional but unique when provided. SQLite treats
            // multiple NULLs as distinct, so a plain unique index gives us
            // "unique when present" without a partial index.
            $table->string('sku')->nullable()->unique();
            $table->string('barcode')->nullable()->unique();
            $table->decimal('selling_price', 12, 2);
            // Item cost is Owner-only; nullable.
            $table->decimal('cost', 12, 2)->nullable();
            $table->integer('low_stock_threshold')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variants');
    }
};
