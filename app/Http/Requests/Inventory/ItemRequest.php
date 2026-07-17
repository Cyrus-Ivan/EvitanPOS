<?php

namespace App\Http\Requests\Inventory;

use App\Models\Item;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation for creating and updating an Item together with its
 * Variants. Authorization is handled by the route `role:admin` middleware, so
 * no authorize() override is needed here (matches the app convention).
 */
abstract class ItemRequest extends FormRequest
{
    /**
     * Second authorization layer (defense-in-depth) so inventory writes don't
     * depend solely on the `role:admin` route group. Mirrors the `manage-inventory`
     * gate — Admins and the Owner.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-inventory') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'category_id' => ['nullable', 'string', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'variants' => ['required', 'array', 'min:1'],
        ];

        // Per-row rules are built dynamically so SKU/barcode uniqueness can ignore
        // the row's own id on update. Uniqueness spans ALL variants (including
        // archived ones) because SKUs/barcodes tie to printed labels and history.
        foreach ((array) $this->input('variants', []) as $index => $variant) {
            $id = is_array($variant) ? ($variant['id'] ?? null) : null;

            $rules["variants.$index.id"] = ['nullable', 'string', 'exists:variants,id'];
            $rules["variants.$index.name"] = ['nullable', 'string', 'max:255'];
            $rules["variants.$index.sku"] = ['required', 'string', 'max:255', Rule::unique('variants', 'sku')->ignore($id)];
            $rules["variants.$index.barcode"] = ['nullable', 'string', 'max:255', Rule::unique('variants', 'barcode')->ignore($id)];
            $rules["variants.$index.selling_price"] = ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'];
            $rules["variants.$index.low_stock_threshold"] = ['nullable', 'integer', 'min:0'];
            $rules["variants.$index.cost"] = ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $variants = $this->input('variants');

        if (! is_array($variants)) {
            return;
        }

        $canViewCost = Gate::allows('view-item-cost');

        $this->merge([
            // Trim the item name so " Coke " and "Coke" are treated as the same
            // for the uniqueness check below (and stored consistently).
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'variants' => array_map(function ($variant) use ($canViewCost) {
                if (! is_array($variant)) {
                    return $variant;
                }

                // Blank SKU/barcode -> null. SQLite treats NULLs as distinct, so a
                // blank barcode never collides and never blocks the sale/save.
                // SKUs are canonically UPPERCASE — normalizing here means the
                // unique rule and the in-payload duplicate check both operate on
                // the value that will actually be stored.
                foreach (['sku', 'barcode'] as $field) {
                    if (array_key_exists($field, $variant)) {
                        $value = is_string($variant[$field]) ? trim($variant[$field]) : $variant[$field];
                        $value = ($value === '' || $value === null) ? null : $value;
                        $variant[$field] = ($field === 'sku' && $value !== null) ? mb_strtoupper($value) : $value;
                    }
                }

                // Item Cost is Owner-only to edit: strip it for anyone who cannot
                // view cost before it reaches validation or mass assignment. This
                // is the authoritative write guard (the model trait only hides the
                // read side).
                if (! $canViewCost) {
                    unset($variant['cost']);
                }

                return $variant;
            }, $variants),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateUniqueItemName($validator);
            $this->validateNoDuplicateIdentifiers($validator);
            $this->validateVariantNaming($validator);
            $this->afterValidation($validator);
        });
    }

    /**
     * Item names are globally unique, compared case-insensitively (trimmed) and
     * spanning archived items — a retired name stays reserved. On update the
     * item's own row is excluded. The DB index (items_name_unique) is the
     * integrity backstop; comparing with the same NOCASE collation the index
     * uses keeps this check and the index in exact agreement.
     */
    private function validateUniqueItemName(Validator $validator): void
    {
        $name = trim((string) $this->input('name'));

        if ($name === '') {
            return; // the `required` rule already reports empties
        }

        $query = Item::whereRaw('name = ? COLLATE NOCASE', [$name]);

        $current = $this->route('item');
        if ($current instanceof Item) {
            $query->whereKeyNot($current->getKey());
        }

        if ($query->exists()) {
            $validator->errors()->add('name', 'An item with this name already exists.');
        }
    }

    /**
     * Two brand-new rows sharing a SKU/barcode each pass the DB-ignore rule
     * individually, so reject in-payload duplicates explicitly.
     */
    private function validateNoDuplicateIdentifiers(Validator $validator): void
    {
        foreach (['sku', 'barcode'] as $field) {
            $seen = [];

            foreach ((array) $this->input('variants', []) as $index => $variant) {
                $value = is_array($variant) ? ($variant[$field] ?? null) : null;

                if ($value === null || $value === '') {
                    continue;
                }

                if (isset($seen[$value])) {
                    $validator->errors()->add(
                        "variants.$index.$field",
                        'This '.$field.' is used by more than one variant.'
                    );
                }

                $seen[$value] = true;
            }
        }
    }

    /**
     * A variant with a blank (null) name is the "no variant" case and is only
     * valid as an item's sole variant. So once an item has more than one variant,
     * every variant must be named — mirroring the importer (CsvInventoryImporter)
     * and keeping the catalog free of the illegal unnamed+named mix.
     */
    private function validateVariantNaming(Validator $validator): void
    {
        $variants = (array) $this->input('variants', []);

        if (count($variants) < 2) {
            return; // a lone unnamed variant is the valid "no variant" item
        }

        foreach ($variants as $index => $variant) {
            $name = is_array($variant) ? ($variant['name'] ?? null) : null;

            if ($name === null || trim((string) $name) === '') {
                $validator->errors()->add(
                    "variants.$index.name",
                    "Give every variant a name — an item with more than one variant can't have a blank (no-variant) row."
                );
            }
        }
    }

    /**
     * Hook for subclasses to add request-specific checks (e.g. variant ownership
     * on update). No-op by default.
     */
    protected function afterValidation(Validator $validator): void
    {
        //
    }
}
