<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Concerns\HidesCostFromNonOwners;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Variant extends Model
{
    use HasUuidPrimaryKey, HidesCostFromNonOwners;

    /** @var list<string> */
    protected $fillable = [
        'item_id',
        'name',
        'sku',
        'barcode',
        'selling_price',
        'cost',
        'low_stock_threshold',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'decimal:2',
            'cost' => 'decimal:2',
            'low_stock_threshold' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return HasMany<InventoryLedger, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(InventoryLedger::class);
    }

    /**
     * @return HasMany<TransactionItem, $this>
     */
    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    /**
     * @param  Builder<Variant>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
