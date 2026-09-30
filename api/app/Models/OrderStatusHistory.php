<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The audit trail behind every status change (§7, §9). Feeds the customer's
 * order timeline and tells an administrator who changed what, and when.
 */
class OrderStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'order_status_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'notified_customer' => 'boolean',
            // Internal rows carry the payment provider's messages about money
            // that went wrong. OrderResource filters them out of the customer
            // timeline; the admin sees everything.
            'is_internal' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Null for a system transition — an expiry job, a webhook. */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
