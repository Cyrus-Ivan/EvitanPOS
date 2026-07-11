<?php

namespace App\Http\Requests\Inventory;

use App\Models\Item;
use Illuminate\Validation\Validator;

class UpdateItemRequest extends ItemRequest
{
    /**
     * Two update-only rules: item names are immutable after creation, and a
     * submitted variant `id` must belong to the item being edited — never
     * allow reassigning another item's variant by forging its id.
     */
    protected function afterValidation(Validator $validator): void
    {
        /** @var Item $item */
        $item = $this->route('item');

        // Item names are immutable. The form disables the field, so this only
        // trips on a forged request; resubmitting the current name (any case)
        // passes because the stored name is kept either way.
        $name = trim((string) $this->input('name'));
        if ($name !== '' && mb_strtolower($name) !== mb_strtolower($item->name)) {
            $validator->errors()->add('name', "An item's name can't be changed after it is created.");
        }

        $ownedIds = $item->variants()->pluck('id')->all();

        foreach ((array) $this->input('variants', []) as $index => $variant) {
            $id = is_array($variant) ? ($variant['id'] ?? null) : null;

            if ($id !== null && ! in_array($id, $ownedIds, true)) {
                $validator->errors()->add(
                    "variants.$index.id",
                    'This variant does not belong to the item.'
                );
            }
        }
    }
}
