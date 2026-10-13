<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * The order as an administrator needs to read it: what was bought, what was
 * charged, where it is going, and everything that has happened to it.
 *
 * Read-only by design. The figures shown are the snapshot taken at purchase,
 * not a recalculation — that is the whole point of storing them.
 */
class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Order')
                ->columns(3)
                ->schema([
                    TextEntry::make('order_number')->label('Order number'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => Order::STATUS_LABELS[$state] ?? $state),
                    TextEntry::make('placed_at')->dateTime('d M Y, H:i'),
                    TextEntry::make('email')->label('Customer email')->copyable(),
                    TextEntry::make('phone')->placeholder('—'),
                    TextEntry::make('fulfillment_type')
                        ->label('Method')
                        ->formatStateUsing(fn (string $state, Order $record) => $state === Order::TYPE_PICKUP
                            ? 'Local pickup'
                            : self::shippingMethod($record)),
                    TextEntry::make('payment_status')->label('Payment'),
                    TextEntry::make('fulfillment_status')->label('Fulfilment'),
                    TextEntry::make('pricing_tier_name')->label('Wholesale tier')->placeholder('Retail'),
                    TextEntry::make('customer_note')
                        ->label('Customer note')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Section::make('Items')
                ->schema([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(5)
                        ->schema([
                            TextEntry::make('product_name')->label('Product'),
                            // The name is the snapshot taken at purchase; the
                            // dot beside it is read live off the variant, so it
                            // goes when the variant does and the name stays.
                            TextEntry::make('color_name')
                                ->label('Colour')
                                ->placeholder('—')
                                ->formatStateUsing(fn (?string $state, OrderItem $record) => new HtmlString(
                                    self::swatch($record).e((string) $state)
                                )),
                            TextEntry::make('variant_sku')->label('SKU'),
                            TextEntry::make('qty')->label('Qty'),
                            TextEntry::make('line_total_cents')
                                ->label('Line total')
                                ->money('CAD', divideBy: 100),
                        ]),
                ]),

            Section::make('Totals')
                ->columns(5)
                ->schema([
                    TextEntry::make('subtotal_cents')->label('Subtotal')->money('CAD', divideBy: 100),
                    TextEntry::make('discount_cents')->label('Wholesale saving')->money('CAD', divideBy: 100),
                    TextEntry::make('shipping_cents')->label('Shipping')->money('CAD', divideBy: 100),
                    TextEntry::make('tax_cents')->label('Tax')->money('CAD', divideBy: 100),
                    TextEntry::make('grand_total_cents')
                        ->label('Grand total')
                        ->money('CAD', divideBy: 100)
                        ->weight('bold'),
                ]),

            // Itemised so the figures on an invoice can be reconciled (§6).
            Section::make('Tax breakdown')
                ->visible(fn (Order $record) => $record->taxes()->exists())
                ->schema([
                    RepeatableEntry::make('taxes')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('tax_type')->label('Tax'),
                            TextEntry::make('rate_bps')
                                ->label('Rate')
                                ->formatStateUsing(fn ($state) => ($state / 100).'%'),
                            TextEntry::make('amount_cents')->label('Amount')->money('CAD', divideBy: 100),
                        ]),
                ]),

            Section::make('Addresses')
                ->schema([
                    RepeatableEntry::make('addresses')
                        ->hiddenLabel()
                        ->columns(2)
                        ->schema([
                            TextEntry::make('type')->label('Type'),
                            TextEntry::make('line1')
                                ->label('Address')
                                ->formatStateUsing(fn (OrderAddress $record) => self::addressLine($record)),
                        ]),
                ]),

            /*
             * How the money moved. This is the screen someone opens when a
             * customer says they were charged twice, or when a chargeback
             * arrives — so it carries the provider's own reference, which is
             * the only thing that reconciles this order against the Stripe
             * dashboard. Card brand and last four are the whole of what is
             * stored about the card itself (§12).
             */
            Section::make('Payment')
                ->schema([
                    RepeatableEntry::make('payments')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('provider')->label('Provider'),
                            TextEntry::make('status')->label('Status'),
                            TextEntry::make('card_last_four')
                                ->label('Card')
                                ->placeholder('—')
                                ->formatStateUsing(fn ($state, $record) => $state
                                    ? trim(($record->card_brand ?? '').' •••• '.$state)
                                    : '—'),
                            TextEntry::make('provider_reference')
                                ->label('Reference')
                                ->placeholder('—')
                                ->copyable(),
                        ]),
                ]),

            Section::make('History')
                ->description('Every status change, and who made it. Rows marked Internal are never shown to the customer.')
                ->schema([
                    RepeatableEntry::make('statusHistory')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('to_status')
                                ->label('Status')
                                ->formatStateUsing(fn (string $state) => Order::STATUS_LABELS[$state] ?? $state),
                            TextEntry::make('note')->label('Note')->placeholder('—'),
                            TextEntry::make('is_internal')
                                ->label('Visibility')
                                ->badge()
                                ->formatStateUsing(fn ($state) => $state ? 'Internal' : 'Customer')
                                ->color(fn ($state) => $state ? 'warning' : 'gray'),
                            TextEntry::make('created_at')->label('When')->dateTime('d M Y, H:i'),
                        ]),
                ]),
        ]);
    }

    /**
     * One address, as a line — or, for a billing address identical to the
     * shipping one, the fact that it is.
     *
     * Almost every order bills where it ships, and printing the same address
     * twice makes the one order that does not look exactly like all the others.
     */
    protected static function addressLine(OrderAddress $address): string
    {
        if ($address->type === OrderAddress::TYPE_BILLING) {
            $shipping = $address->order?->shippingAddress;

            if ($shipping && $shipping->lines() === $address->lines()) {
                return 'Same as shipping address';
            }
        }

        return implode(', ', $address->lines());
    }

    /**
     * A dot in the variant's colour, or nothing.
     *
     * The hex is free text typed into the colours table by an operator, and
     * this is the one place it is written into markup — into a style attribute,
     * unescaped, because that is what a colour is for. So it is only ever
     * written when it is a hex colour and nothing else; anything that fails the
     * pattern produces no dot rather than a best guess.
     */
    protected static function swatch(OrderItem $item): string
    {
        $hex = $item->variant?->color?->hex;

        if (! is_string($hex) || ! preg_match('/\A#(?:[0-9a-fA-F]{3}){1,2}\z/', $hex)) {
            return '';
        }

        return '<span class="bsd-swatch" style="display:inline-block;width:.7rem;height:.7rem;'
            .'margin-right:.4rem;border-radius:9999px;border:1px solid rgba(0,0,0,.2);'
            .'vertical-align:-.05rem;background:'.$hex.'"></span>';
    }

    /**
     * "Ship — Express (Purolator Express)": what the customer paid for, then
     * the carrier's service it was on the day, which is the label to buy.
     *
     * A table rate names no carrier, and an order from before the service was
     * recorded names nothing at all; both say only as much as they know.
     */
    protected static function shippingMethod(Order $order): string
    {
        if (! $order->shipping_method) {
            return 'Ship';
        }

        $carrier = (string) $order->shipping_carrier;
        $service = (string) $order->shipping_service;

        // "Intelcom" + "Intelcom Standard" is one name, not two.
        $carried = $carrier !== '' && stripos($service, $carrier) !== 0
            ? trim($carrier.' '.$service)
            : $service;

        return $carried === '' || $carried === $order->shipping_method
            ? "Ship — {$order->shipping_method}"
            : "Ship — {$order->shipping_method} ({$carried})";
    }
}
