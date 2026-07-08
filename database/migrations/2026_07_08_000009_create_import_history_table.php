<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('filename')->nullable();
            // Allowed: replace_stock_count | add_to_current_stock
            $table->string('mode');
            // Allowed: success | failed | partial
            $table->string('status');
            $table->integer('total_rows')->nullable();
            $table->integer('success_count')->nullable();
            $table->integer('error_count')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_history');
    }
};
