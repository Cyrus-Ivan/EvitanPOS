<?php

namespace App\Http\Requests\Inventory;

use App\Models\Item;
use Illuminate\Validation\Validator;

class UpdateItemRequest extends ItemRequest
{
    /**
     * A submitted variant `id` must belong to the item being edited — never
     * allow reassigning another item's variant by forging its id.
     */
    protected function afterValidation(Validator $validator): void
    {
        /** @var Item $item */
        $item = $this->route('item');
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
