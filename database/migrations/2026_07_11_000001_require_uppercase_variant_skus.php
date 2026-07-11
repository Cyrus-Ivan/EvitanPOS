<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SKUs become mandatory and canonically UPPERCASE (Phase 7 hardening). Every
 * write path (item form, CSV import, Variant sku mutator) now uppercases on the
 * way in; this migration brings pre-existing rows in line and then locks the
 * column down with NOT NULL so a variant can never lack a SKU again.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Stage 1: uppercase existing SKUs. The unique index is case-sensitive,
        // so "abc" and "ABC" may coexist today; resolve such case-only
        // collisions deterministically — the oldest row keeps the clean value,
        // later ones get -2, -3, ... suffixes.
        //
        // Decide every row's final SKU before writing anything: renaming rows
        // one at a time would collide with a not-yet-renamed row that currently
        // holds the target value ("abc" -> "ABC" while a newer row still owns
        // "ABC" trips the unique index mid-migration).
        $taken = [];
        $final = [];

        $variants = DB::table('variants')
            ->whereNotNull('sku')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'sku']);

        foreach ($variants as $variant) {
            $upper = mb_strtoupper(trim($variant->sku));
            $candidate = $upper;
            $n = 2;

            while (isset($taken[$candidate])) {
                $candidate = $upper.'-'.$n++;
            }

            $taken[$candidate] = true;
            $final[$variant->id] = $candidate;
        }

        // Apply in two passes: park every changing row on a placeholder that
        // cannot clash with any real SKU (it embeds the row's own UUID), then
        // set the final values now that the old ones are out of the way.
        $changing = array_filter(
            $variants->all(),
            fn (object $variant) => $final[$variant->id] !== $variant->sku
        );

        foreach ($changing as $variant) {
            DB::table('variants')->where('id', $variant->id)->update(['sku' => '@RENAMING:'.$variant->id]);
        }

        foreach ($changing as $variant) {
            DB::table('variants')->where('id', $variant->id)->update(['sku' => $final[$variant->id]]);
        }

        // Stage 2: backfill NULL SKUs from the parent item's name, e.g.
        // "Bottled Water" -> SKU-BOTTLED-WATER (second one -> ...-2).
        $missing = DB::table('variants')
            ->whereNull('variants.sku')
            ->join('items', 'items.id', '=', 'variants.item_id')
            ->orderBy('variants.created_at')
            ->orderBy('variants.id')
            ->get(['variants.id as vid', 'items.name as item_name']);

        foreach ($missing as $variant) {
            $slug = mb_substr(mb_strtoupper(Str::slug($variant->item_name ?: 'ITEM')), 0, 40);
            $base = 'SKU-'.($slug === '' ? 'ITEM' : $slug);
            $candidate = $base;
            $n = 2;

            while (isset($taken[$candidate])) {
                $candidate = $base.'-'.$n++;
            }

            $taken[$candidate] = true;

            DB::table('variants')->where('id', $variant->vid)->update(['sku' => $candidate]);
        }

        // Stage 3: lock the column down. SQLite rebuilds the table; indexes
        // (variants_sku_unique, variants_barcode_unique) are retained.
        Schema::table('variants', function (Blueprint $table) {
            $table->string('sku')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // Only the constraint is reversible — the uppercase/backfill data
        // transforms are not undone.
        Schema::table('variants', function (Blueprint $table) {
            $table->string('sku')->nullable()->change();
        });
    }
};
