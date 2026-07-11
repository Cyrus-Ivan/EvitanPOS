<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Variant;
use App\Support\Inventory\InventoryCsvSchema;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inventory CSV export (Phase 7). Admin+ (route middleware). Streams every
 * active variant (with its item + category) using the same column schema as the
 * import template, so an export round-trips cleanly back through the importer.
 *
 * Item Cost is Owner-only: the cost column is omitted entirely for anyone who
 * cannot view cost. We build rows by reading $variant->cost directly (bypassing
 * the HidesCostFromNonOwners toArray() guard), so the omission is enforced here
 * by the header set and an explicit gate check.
 */
class InventoryExportController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $includeCost = Gate::allows('view-item-cost');
        $headers = InventoryCsvSchema::headers($includeCost);
        $filename = 'inventory-export-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($headers, $includeCost) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers, escape: '');

            Variant::query()
                ->active()
                ->whereHas('item', fn ($query) => $query->active())
                ->with(['item:id,name,unit,description,category_id', 'item.category:id,name', 'stockSnapshot'])
                ->orderBy('sku')
                ->chunk(500, function ($variants) use ($out, $headers, $includeCost) {
                    foreach ($variants as $variant) {
                        $row = [
                            'category' => $variant->item->category?->name ?? '',
                            'product' => $variant->item->name,
                            'variant' => $variant->name,
                            'sku' => $variant->sku,
                            'barcode' => $variant->barcode ?? '',
                            'selling_price' => $variant->selling_price,
                            'cost' => $includeCost ? ($variant->cost ?? '') : '',
                            'stock' => $variant->current_stock,
                            'unit' => $variant->item->unit,
                            'description' => $variant->item->description ?? '',
                            'low_stock_threshold' => $variant->low_stock_threshold,
                            // Left blank on purpose: an unedited re-import must not
                            // silently overwrite live stock. The user has to pick a
                            // mode per row (mode is required on import), which forces
                            // a deliberate choice between replacing and adding stock.
                            'mode' => '',
                        ];

                        fputcsv($out, array_map(fn (string $column) => (string) $row[$column], $headers), escape: '');
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
