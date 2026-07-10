<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        // Item names are globally unique, case-insensitively and spanning
        // archived rows (a retired name stays reserved). COLLATE NOCASE folds
        // case in the uniqueness check; a raw statement is used because the
        // schema builder can't attach a collation to a unique index.
        DB::statement('CREATE UNIQUE INDEX items_name_unique ON items (name COLLATE NOCASE)');
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
