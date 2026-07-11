<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactions gain an optional free-text comment, captured once at checkout
 * (e.g. "held for pickup", a reference note). Like everything else on a
 * transaction it is immutable business history — set at sale time, never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->text('comment')->nullable()->after('change');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('comment');
        });
    }
};
