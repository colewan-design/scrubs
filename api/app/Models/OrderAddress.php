<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A frozen copy of where an order went — never a live reference to an address book row. */
class OrderAddress extends Model
{
    use HasFactory;

    public const TYPE_SHIPPING = 'shipping';
    public const TYPE_BILLING = 'billing';

    protected $guarded = ['id'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function name(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** @return array<int, string> Address lines, ready to print one per row. */
    public function lines(): array
    {
        return array_values(array_filter([
            $this->name(),
            $this->company,
            $this->line1,
            $this->line2,
            trim("{$this->city}, {$this->province}  {$this->postal_code}"),
            $this->country,
        ]));
    }
}
