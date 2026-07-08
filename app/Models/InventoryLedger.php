<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only, immutable inventory ledger. Rows are never edited or deleted;
 * corrections are new compensating entries. Only created_at is tracked.
 */
class InventoryLedger extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'inventory_ledger';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'variant_id',
        'quantity',
        'resulting_snapshot',
        'type',
        'sale_type',
        'reference_id',
        'reference_type',
        'notes',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'resulting_snapshot' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
