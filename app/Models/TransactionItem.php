<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Concerns\HidesCostFromNonOwners;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionItem extends Model
{
    use HasUuidPrimaryKey, HidesCostFromNonOwners;

    /** @var list<string> */
    protected $fillable = [
        'transaction_id',
        'variant_id',
        'line_type',
        'quantity',
        'selling_price',
        'cost',
        'discount',
        'total',
        'source_transaction_item_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'selling_price' => 'decimal:2',
            'cost' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * The original sold line this return/defect line was created from.
     *
     * @return BelongsTo<TransactionItem, $this>
     */
    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(TransactionItem::class, 'source_transaction_item_id');
    }

    /**
     * @return HasMany<TransactionItem, $this>
     */
    public function relatedItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class, 'source_transaction_item_id');
    }
}
