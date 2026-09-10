<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                        ->formatStateUsing(fn (string $state) => $state === Order::TYPE_PICKUP ? 'Local pickup' : 'Ship'),
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
                        ->columns(4)
                        ->schema([
                            TextEntry::make('product_name')->label('Product'),
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
                                ->formatStateUsing(fn ($state, $record) => implode(', ', $record->lines())),
                        ]),
                ]),

            Section::make('History')
                ->description('Every status change, and who made it.')
                ->schema([
                    RepeatableEntry::make('statusHistory')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('to_status')
                                ->label('Status')
                                ->formatStateUsing(fn (string $state) => Order::STATUS_LABELS[$state] ?? $state),
                            TextEntry::make('note')->label('Note')->placeholder('—'),
                            TextEntry::make('created_at')->label('When')->dateTime('d M Y, H:i'),
                        ]),
                ]),
        ]);
    }
}
