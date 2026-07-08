<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Gate;

/**
 * Item Cost is Owner-only. Any model carrying a snapshotted or live `cost`
 * column mixes this in so the field is stripped from array/JSON output for
 * anyone who can't view cost — enforcing "cost never appears in API responses
 * for non-Owners" at the serialization layer, before any endpoint can leak it.
 */
trait HidesCostFromNonOwners
{
    public function toArray(): array
    {
        $data = parent::toArray();

        // No authenticated user (CLI, queue, unauthenticated request) is treated
        // as "cannot view cost" — hide it by default.
        if (! Gate::allows('view-item-cost')) {
            unset($data['cost']);
        }

        return $data;
    }
}
