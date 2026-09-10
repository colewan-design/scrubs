<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only ledger. When stock is wrong, this is how anyone finds out why.
 */
class InventoryMovement extends Model
{
    use HasFactory;

    public const REASON_INITIAL = 'initial';
    public const REASON_PURCHASE = 'purchase';
    public const REASON_MANUAL = 'manual_adjustment';
    public const REASON_REFUND_RESTOCK = 'refund_restock';
    public const REASON_CANCELLATION = 'cancellation';
    public const REASON_CORRECTION = 'correction';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['delta' => 'integer', 'balance_after' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
