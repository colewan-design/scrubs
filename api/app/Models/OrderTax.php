<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One applied tax on one order, so checkout and invoices can itemise
 * "HST 13% — $8.45" exactly as §6 requires.
 */
class OrderTax extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate_bps' => 'integer',
            'taxable_base_cents' => 'integer',
            'amount_cents' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** "HST 13%" — the label a customer sees on the receipt. */
    public function label(): string
    {
        return sprintf('%s %s%%', $this->tax_type, rtrim(rtrim(number_format($this->rate_bps / 100, 2), '0'), '.'));
    }
}
