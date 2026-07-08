<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // Free-text unit with a sensible character limit.
            $table->string('unit', 50);
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
