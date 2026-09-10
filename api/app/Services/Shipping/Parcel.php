<?php

namespace App\Services\Shipping;

use App\Services\Pricing\QuotedLine;
use Illuminate\Support\Collection;

/**
 * What is being shipped, reduced to the two facts a rate table needs: how heavy
 * it is and what it is worth.
 *
 * `unweighedItems` is carried deliberately. Per-variant weights are outstanding
 * client data (Risk R4), and a live carrier rate computed from a partial weight
 * would be quietly wrong rather than obviously missing. Anything relying on
 * real weights must check this first.
 */
final class Parcel
{
    public function __construct(
        public readonly int $weightGrams,
        public readonly int $subtotalCents,
        public readonly int $itemCount,
        public readonly int $unweighedItems = 0,
    ) {}

    /** @param  Collection<int, QuotedLine>|array<int, QuotedLine>  $lines */
    public static function fromQuotedLines(iterable $lines, int $subtotalCents): self
    {
        $weight = 0;
        $count = 0;
        $unweighed = 0;

        foreach ($lines as $line) {
            $count += $line->qty;
            $grams = $line->variant->weight_grams;

            if ($grams === null) {
                $unweighed += $line->qty;

                continue;
            }

            $weight += $grams * $line->qty;
        }

        return new self($weight, $subtotalCents, $count, $unweighed);
    }

    public function hasCompleteWeights(): bool
    {
        return $this->unweighedItems === 0;
    }
}
