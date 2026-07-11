<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Variant names become nullable: a single-variant ("no variant") item now keeps
 * one variant whose name is null and the UI falls back to the item name. The
 * create-variants migration was updated in place for fresh installs, but a DB
 * migrated before that edit still carries NOT NULL — this brings it in line,
 * exactly like 2026_07_11_000001 does for SKUs.
 *
 * It also converts the legacy blank-name fallback: the old item form stored
 * 'Default' when the name was left empty. A 'Default' variant that is its
 * item's only variant is the "no variant" case and becomes null; where an item
 * has other variants, 'Default' stays as a real (user-visible) name so the
 * migration never creates the illegal unnamed+named mix.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op on a fresh install (the create migration already declares the
        // column nullable); rebuilds the table on an upgraded DB.
        Schema::table('variants', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });

        $soleDefaults = DB::table('variants as v')
            ->where('v.name', 'Default')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('variants as siblings')
                    ->whereColumn('siblings.item_id', 'v.item_id')
                    ->whereColumn('siblings.id', '!=', 'v.id');
            })
            ->pluck('v.id');

        DB::table('variants')->whereIn('id', $soleDefaults)->update(['name' => null]);
    }

    public function down(): void
    {
        // Restore the old blank-name fallback before re-tightening the column,
        // or the NOT NULL change would fail on the rows nulled above.
        DB::table('variants')->whereNull('name')->update(['name' => 'Default']);

        Schema::table('variants', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });
    }
};
