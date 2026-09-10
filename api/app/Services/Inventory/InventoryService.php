<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manual stock corrections (§2: "Administrator must be able to manually add or
 * adjust stock").
 *
 * Order-driven stock movement stays in OrderService, which owns reservation and
 * commit. This class exists for the movements a human initiates: a delivery
 * arriving, a miscount, damaged goods written off.
 *
 * The invariant is the same as the order side — stock_qty is never written
 * without a matching inventory_movements row. The ledger is what makes a
 * balance explainable a month later, so an untracked adjustment is a bug.
 */
class InventoryService
{
    /**
     * Apply a signed change to a variant's stock.
     *
     * @param  int  $delta  positive to add, negative to remove
     *
     * @throws RuntimeException when the change would push stock below what is
     *                          already reserved for in-flight checkouts
     */
    public function adjust(
        ProductVariant $variant,
        int $delta,
        string $reason = InventoryMovement::REASON_MANUAL,
        ?string $note = null,
        ?User $actor = null,
    ): ProductVariant {
        if ($delta === 0) {
            return $variant;
        }

        return DB::transaction(function () use ($variant, $delta, $reason, $note, $actor) {
            // Lock first: two administrators counting the same shelf at once must
            // not both write a balance derived from the same stale read.
            $variant = ProductVariant::query()
                ->whereKey($variant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $balance = $variant->stock_qty + $delta;

            if ($balance < 0) {
                throw new RuntimeException('Stock cannot go below zero.');
            }

            // Reserved units belong to checkouts already in flight. Removing them
            // from under a customer mid-payment is the one adjustment that is
            // never a correction, so it is refused rather than warned about.
            if ($balance < $variant->reserved_qty) {
                throw new RuntimeException(
                    "{$variant->reserved_qty} unit(s) are reserved for in-flight orders. "
                    ."Stock cannot be set below that until those orders are paid or released."
                );
            }

            $variant->forceFill(['stock_qty' => $balance])->save();

            InventoryMovement::create([
                'product_variant_id' => $variant->id,
                'delta' => $delta,
                'balance_after' => $balance,
                'reason' => $reason,
                'user_id' => $actor?->id,
                'note' => $note,
            ]);

            return $variant;
        });
    }

    /**
     * Set stock to an absolute figure — what a stock-take produces. Expressed as
     * a delta so the ledger still records the movement rather than a bare
     * overwrite.
     */
    public function setTo(
        ProductVariant $variant,
        int $target,
        ?string $note = null,
        ?User $actor = null,
    ): ProductVariant {
        return $this->adjust(
            $variant,
            $target - $variant->stock_qty,
            InventoryMovement::REASON_CORRECTION,
            $note ?? 'Stock count correction.',
            $actor,
        );
    }
}
