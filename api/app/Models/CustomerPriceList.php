<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * V2 seam (§13). Not exposed at launch, but PricingService already resolves it
 * ahead of global tiers so adding it later is configuration, not a refactor.
 */
class CustomerPriceList extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerPriceListItem::class);
    }
}
