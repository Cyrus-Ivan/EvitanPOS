<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Concerns\HidesCostFromNonOwners;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionItem extends Model
{
    use HasUuidPrimaryKey, HidesCostFromNonOwners;

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
}
