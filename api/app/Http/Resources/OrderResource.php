<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderAddress;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as the customer sees it (§7).
 *
 * Every figure is the snapshot stored on the order, never a live recalculation:
 * a price edited in admin next month must not change what this receipt says.
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order_number' => $this->order_number,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'payment_status' => $this->payment_status,
            'fulfillment_status' => $this->fulfillment_status,
            'fulfillment_type' => $this->fulfillment_type,
            'email' => $this->email,
            'phone' => $this->phone,
            'customer_note' => $this->customer_note,
            'is_cancellable' => $this->isCancellable(),

            'pricing_tier_name' => $this->pricing_tier_name,
            'totals' => [
                'subtotal' => MoneyResource::make($this->subtotal_cents),
                'discount' => MoneyResource::make($this->discount_cents),
                'shipping' => MoneyResource::make($this->shipping_cents),
                'tax' => MoneyResource::make($this->tax_cents),
                'grand_total' => MoneyResource::make($this->grand_total_cents),
            ],

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_name' => $item->product_name,
                'variant_sku' => $item->variant_sku,
                'variant_label' => $item->variantLabel(),
                'qty' => $item->qty,
                'unit_retail' => MoneyResource::make($item->unit_retail_cents),
                'unit_price' => MoneyResource::make($item->unit_price_cents),
                'line_discount' => MoneyResource::make($item->line_discount_cents),
                'line_total' => MoneyResource::make($item->line_total_cents),
                'pricing_tier_name' => $item->pricing_tier_name,
            ])),

            // Itemised so a receipt can print "HST 13% — $8.45" (§6).
            'taxes' => $this->whenLoaded('taxes', fn () => $this->taxes->map(fn ($tax) => [
                'label' => $tax->label(),
                'tax_type' => $tax->tax_type,
                'province' => $tax->province,
                'amount' => MoneyResource::make($tax->amount_cents),
            ])),

            // The seller's GST/HST number, which §6 requires on a receipt that
            // charges tax. Frozen at placement; see the migration for why NULL
            // and empty mean different things.
            'tax_registration' => $this->taxRegistration(),

            'shipping_address' => $this->whenLoaded(
                'addresses',
                fn () => $this->addressPayload(OrderAddress::TYPE_SHIPPING)
            ),
            'billing_address' => $this->whenLoaded(
                'addresses',
                fn () => $this->addressPayload(OrderAddress::TYPE_BILLING)
            ),

            'shipments' => $this->whenLoaded('shipments', fn () => $this->shipments->map(fn ($s) => [
                'carrier' => $s->carrier,
                'service' => $s->service,
                'tracking_number' => $s->tracking_number,
                'tracking_url' => $s->tracking_url,
                'shipped_at' => $s->shipped_at?->toIso8601String(),
            ])),

            // The customer-facing timeline. Internal notes are admin-only and
            // deliberately absent here.
            'timeline' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($h) => [
                'status' => $h->to_status,
                'status_label' => Order::STATUS_LABELS[$h->to_status] ?? $h->to_status,
                'note' => $h->note,
                'at' => $h->created_at?->toIso8601String(),
            ])),

            'placed_at' => $this->placed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
        ];
    }

    /**
     * The registration number to print on this receipt (§6).
     *
     * NULL means the order predates the column, so the current setting is the
     * best information available. An empty string is a deliberate record that
     * the business held no number when the order was placed, and stays empty
     * even after one is configured — a February receipt must not grow a number
     * the seller only obtained in March.
     */
    protected function taxRegistration(): ?string
    {
        $frozen = $this->tax_registration;

        if ($frozen === null) {
            $frozen = app(Settings::class)->string('tax.gst_number');
        }

        return $frozen !== '' ? $frozen : null;
    }

    protected function addressPayload(string $type): ?array
    {
        $address = $this->addresses->firstWhere('type', $type);

        if (! $address) {
            return null;
        }

        return [
            'name' => $address->name(),
            'company' => $address->company,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'province' => $address->province,
            'postal_code' => $address->postal_code,
            'country' => $address->country,
            'phone' => $address->phone,
            'lines' => $address->lines(),
        ];
    }
}
