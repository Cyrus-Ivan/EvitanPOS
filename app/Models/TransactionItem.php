<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Concerns\Immutable;
use App\Models\Concerns\HidesCostFromNonOwners;
use Database\Factories\TransactionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionItem extends Model
{
    /** @use HasFactory<TransactionItemFactory> */
    use HasFactory, HasUuidPrimaryKey, HidesCostFromNonOwners, Immutable;

    // Append-only sale line: immutable business history.
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'transaction_id',
        'variant_id',
        'quantity',
        'selling_price',
        'cost',
        'discount',
        'total',
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
     * After-sales lines (returns / defects) written against this sold
     * line. Their cumulative quantity can never exceed this line's quantity.
     *
     * @return HasMany<SaleReturnItem, $this>
     */
    public function returnItems(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class, 'transaction_item_id');
    }
}
