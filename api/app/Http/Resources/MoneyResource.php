<?php

namespace App\Http\Resources;

/**
 * Money crosses the API as integer cents plus a preformatted display string.
 *
 * The frontend never does arithmetic on money and never formats currency
 * itself — it renders what the server sent. That is the single most effective
 * guard against the storefront and the order disagreeing about a total.
 */
class MoneyResource
{
    public static function make(?int $cents, string $currency = 'CAD'): ?array
    {
        if ($cents === null) {
            return null;
        }

        return [
            'cents' => $cents,
            'formatted' => '$'.number_format($cents / 100, 2),
            'currency' => $currency,
        ];
    }
}
