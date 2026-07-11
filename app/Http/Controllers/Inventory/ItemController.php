<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreItemRequest;
use App\Http\Requests\Inventory\UpdateItemRequest;
use App\Models\Category;
use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ItemController extends Controller
{
    /**
     * Paginated item catalog with search, category filter and archived filter.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', '')) ?: null;
        $categoryId = $request->query('category') ?: null;
        $status = $this->normalizeStatus($request->query('status'));

        $items = Item::query()
            ->with([
                'category:id,name',
                'variants' => fn ($query) => $query->active()->with('stockSnapshot')->orderBy('created_at'),
            ])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->whereLike('name', $search)
                        ->orWhereHas('variants', fn ($variant) => $variant->whereLikeAny(['sku', 'barcode'], $search));
                });
            })
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->when($status === 'active', fn ($query) => $query->whereNull('archived_at'))
            ->when($status === 'archived', fn ($query) => $query->whereNotNull('archived_at'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        // Surface current stock (from the eager-loaded snapshot) on each variant.
        $items->getCollection()->each(
            fn (Item $item) => $item->variants->each->append('current_stock'),
        );

        return Inertia::render('inventory/index', [
            'items' => $items,
            'categories' => Category::active()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $search,
                'category' => $categoryId,
                'status' => $status,
            ],
        ]);
    }

    /**
     * Show the "new item" form.
     */
    public function create(): Response
    {
        return Inertia::render('inventory/create', [
            'categories' => Category::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreItemRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data) {
            $item = Item::create([
                'name' => $data['name'],
                'unit' => $data['unit'],
                'category_id' => $data['category_id'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            foreach ($data['variants'] as $variant) {
                $item->variants()->create($this->variantAttributes($variant));
            }
        });

        // TODO(Phase 15): audit inventory.item.created

        return to_route('inventory')->with('toast', [
            'type' => 'success',
            'message' => 'Item created.',
        ]);
    }

    /**
     * Show the edit form with the item's active variants.
     */
    public function edit(Item $item): Response
    {
        $item->load(['variants' => fn ($query) => $query->active()->orderBy('created_at')]);

        return Inertia::render('inventory/edit', [
            'item' => $item,
            'categories' => Category::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateItemRequest $request, Item $item): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $item) {
            // `name` is deliberately absent: item names are immutable after
            // creation (UpdateItemRequest rejects a differing name; even a
            // case-only resubmission must not drift the stored value).
            $item->update([
                'unit' => $data['unit'],
                'category_id' => $data['category_id'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            $existing = $item->variants()->get()->keyBy('id');
            $keptIds = [];

            foreach ($data['variants'] as $variant) {
                $id = $variant['id'] ?? null;

                if ($id !== null && $existing->has($id)) {
                    $existing->get($id)->update($this->variantAttributes($variant));
                    $keptIds[] = $id;
                } else {
                    $keptIds[] = $item->variants()->create($this->variantAttributes($variant))->id;
                }
            }

            // Variants removed from the form are archived, never deleted. Newly
            // created variants are in $keptIds, so they are not swept here.
            $item->variants()
                ->active()
                ->whereNotIn('id', $keptIds)
                ->update(['archived_at' => now()]);

            // Every item must keep at least one active variant.
            if ($item->variants()->active()->count() === 0) {
                throw ValidationException::withMessages([
                    'variants' => 'An item must have at least one active variant.',
                ]);
            }
        });

        // TODO(Phase 15): audit inventory.item.updated

        return to_route('inventory')->with('toast', [
            'type' => 'success',
            'message' => 'Item updated.',
        ]);
    }

    public function archive(Item $item): RedirectResponse
    {
        $item->update(['archived_at' => now()]);

        // TODO(Phase 15): audit inventory.item.archived

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Item archived.',
        ]);
    }

    public function restore(Item $item): RedirectResponse
    {
        $item->update(['archived_at' => null]);

        // TODO(Phase 15): audit inventory.item.restored

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Item restored.',
        ]);
    }

    /**
     * Build the persistable variant attributes. `cost` is present only when the
     * actor may edit it (stripped upstream in the request otherwise), so it is
     * copied through conditionally and never overwritten with null.
     *
     * @param  array<string, mixed>  $variant
     * @return array<string, mixed>
     */
    private function variantAttributes(array $variant): array
    {
        $name = $variant['name'] ?? null;
        $name = is_string($name) ? trim($name) : $name;

        $attributes = [
            // A blank name persists as null: single-variant ("no variant") items
            // keep one unnamed variant that simply references the parent item.
            'name' => ($name === null || $name === '') ? null : $name,
            'sku' => $variant['sku'] ?? null,
            'barcode' => $variant['barcode'] ?? null,
            'selling_price' => $variant['selling_price'],
            'low_stock_threshold' => $variant['low_stock_threshold'] ?? 0,
        ];

        if (array_key_exists('cost', $variant)) {
            $attributes['cost'] = $variant['cost'];
        }

        return $attributes;
    }

    /**
     * Constrain the archived filter to a known tri-state, defaulting to active.
     */
    private function normalizeStatus(mixed $status): string
    {
        return in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active';
    }
}
