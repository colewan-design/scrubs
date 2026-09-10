<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of provinces sharing one rate table (§5).
 *
 * Zones are ordered by position and the first one containing the destination
 * wins, so a specific zone can be placed above a catch-all without needing
 * priority logic anywhere else.
 */
class ShippingZone extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'provinces' => 'array',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class)->orderBy('position');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** An empty province list is the catch-all — it covers everywhere. */
    public function covers(string $province): bool
    {
        $provinces = $this->provinces ?? [];

        return $provinces === [] || in_array(strtoupper($province), array_map('strtoupper', $provinces), true);
    }
}
