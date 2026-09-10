<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\Support\CsvExportAction;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Orders\OrderService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('placed_at', 'desc')
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('placed_at')
                    ->label('Placed')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Customer')
                    ->description(fn (Order $r) => $r->phone)
                    ->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Order::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Order::STATUS_PENDING_PAYMENT => 'warning',
                        Order::STATUS_CANCELLED, Order::STATUS_REFUNDED => 'danger',
                        Order::STATUS_COMPLETED => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('fulfillment_type')
                    ->label('Method')
                    ->formatStateUsing(fn (string $state) => $state === Order::TYPE_PICKUP ? 'Pickup' : 'Ship'),

                TextColumn::make('pricing_tier_name')
                    ->label('Tier')
                    ->placeholder('Retail'),

                // Money is stored in cents everywhere; dividing here is display
                // only, and no arithmetic on it ever leaves this column.
                TextColumn::make('grand_total_cents')
                    ->label('Total')
                    ->money('CAD', divideBy: 100)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Order::STATUS_LABELS),
                SelectFilter::make('fulfillment_type')
                    ->label('Method')
                    ->options([Order::TYPE_SHIP => 'Ship', Order::TYPE_PICKUP => 'Pickup']),
            ])
            ->headerActions([
                CsvExportAction::make('orders', [
                    'Order number' => fn (Order $o) => $o->order_number,
                    'Placed at' => fn (Order $o) => $o->placed_at?->toDateTimeString(),
                    'Status' => fn (Order $o) => $o->statusLabel(),
                    'Payment status' => fn (Order $o) => $o->payment_status,
                    'Fulfilment' => fn (Order $o) => $o->fulfillment_status,
                    'Type' => fn (Order $o) => $o->fulfillment_type,
                    'Email' => fn (Order $o) => $o->email,
                    'Phone' => fn (Order $o) => $o->phone,
                    'Tier' => fn (Order $o) => $o->pricing_tier_name,
                    'Subtotal' => fn (Order $o) => CsvExportAction::money($o->subtotal_cents),
                    'Discount' => fn (Order $o) => CsvExportAction::money($o->discount_cents),
                    'Shipping' => fn (Order $o) => CsvExportAction::money($o->shipping_cents),
                    'Tax' => fn (Order $o) => CsvExportAction::money($o->tax_cents),
                    'Total' => fn (Order $o) => CsvExportAction::money($o->grand_total_cents),
                    'Refunded' => fn (Order $o) => CsvExportAction::money($o->totalRefundedCents()),
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(self::statusActions()),
            ]);
    }

    /**
     * Every status change goes through OrderService: it decides the derived
     * status, writes history and refuses illegal transitions. Nothing here sets
     * a status column directly.
     *
     * @return array<int, Action>
     */
    public static function statusActions(): array
    {
        return [
            Action::make('markPaid')
                ->label('Mark paid')
                ->icon('heroicon-o-banknotes')
                ->requiresConfirmation()
                ->modalDescription('This moves stock off the shelf and starts fulfilment.')
                ->visible(fn (Order $record) => $record->payment_status === Order::PAYMENT_PENDING
                    && $record->fulfillment_status !== Order::FULFILLMENT_CANCELLED)
                ->action(fn (Order $record) => self::run(
                    fn () => app(OrderService::class)->markPaid($record, null, auth()->user()),
                    'Payment recorded.'
                )),

            Action::make('readyForPickup')
                ->label('Ready for pickup')
                ->icon('heroicon-o-building-storefront')
                ->visible(fn (Order $record) => $record->isPickup()
                    && $record->fulfillment_status === Order::FULFILLMENT_PROCESSING)
                ->action(fn (Order $record) => self::run(
                    fn () => app(OrderService::class)->transitionFulfillment(
                        $record,
                        Order::FULFILLMENT_READY_FOR_PICKUP,
                        'Ready for collection.',
                        auth()->user(),
                    ),
                    'Customer can collect this order.'
                )),

            Action::make('markShipped')
                ->label('Mark shipped')
                ->icon('heroicon-o-truck')
                ->visible(fn (Order $record) => ! $record->isPickup()
                    && $record->fulfillment_status === Order::FULFILLMENT_PROCESSING)
                ->schema([
                    TextInput::make('carrier')->label('Carrier')->maxLength(80),
                    TextInput::make('tracking_number')->label('Tracking number')->maxLength(120),
                    TextInput::make('tracking_url')->label('Tracking link')->url()->maxLength(500),
                ])
                ->action(function (Order $record, array $data) {
                    self::run(function () use ($record, $data) {
                        // The shipment row is what the customer's tracking link
                        // reads from, so it is written before the transition.
                        if (array_filter($data)) {
                            $record->shipments()->create([
                                'carrier' => $data['carrier'] ?? null,
                                'tracking_number' => $data['tracking_number'] ?? null,
                                'tracking_url' => $data['tracking_url'] ?? null,
                                'status' => Shipment::STATUS_SHIPPED,
                                'shipped_at' => now(),
                            ]);
                        }

                        app(OrderService::class)->transitionFulfillment(
                            $record,
                            Order::FULFILLMENT_SHIPPED,
                            $data['tracking_number'] ? "Shipped — {$data['tracking_number']}" : 'Shipped.',
                            auth()->user(),
                        );
                    }, 'Order marked as shipped.');
                }),

            Action::make('markCompleted')
                ->label('Mark completed')
                ->icon('heroicon-o-check-circle')
                ->visible(fn (Order $record) => in_array($record->fulfillment_status, [
                    Order::FULFILLMENT_SHIPPED,
                    Order::FULFILLMENT_READY_FOR_PICKUP,
                ], true))
                ->action(fn (Order $record) => self::run(
                    fn () => app(OrderService::class)->transitionFulfillment(
                        $record,
                        Order::FULFILLMENT_COMPLETED,
                        'Order completed.',
                        auth()->user(),
                    ),
                    'Order completed.'
                )),

            Action::make('cancel')
                ->label('Cancel order')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Stock held or sold by this order is returned.')
                ->schema([
                    Textarea::make('reason')->label('Reason')->rows(2)->maxLength(500),
                ])
                ->visible(fn (Order $record) => $record->isCancellable())
                ->action(fn (Order $record, array $data) => self::run(
                    fn () => app(OrderService::class)->cancel(
                        $record,
                        $data['reason'] ?: 'Cancelled by admin.',
                        auth()->user(),
                    ),
                    'Order cancelled and stock returned.'
                )),

            // Records money already returned to the customer — it does not move
            // money itself. Once a processor is integrated the provider call
            // goes in front of OrderService::refund(), not in place of it.
            Action::make('refund')
                ->label('Record refund')
                ->icon('heroicon-o-receipt-refund')
                ->color('warning')
                ->visible(fn (Order $record) => in_array($record->payment_status, [
                    Order::PAYMENT_PAID,
                    Order::PAYMENT_PARTIALLY_REFUNDED,
                ], true) && $record->outstandingRefundableCents() > 0)
                ->modalHeading(fn (Order $record) => "Record refund — {$record->order_number}")
                ->modalDescription('Refund the customer through your payment provider first, then record it here.')
                ->schema([
                    TextInput::make('amount')
                        ->label('Amount')
                        ->numeric()
                        ->prefix('CA$')
                        ->step('0.01')
                        ->required()
                        ->default(fn (Order $record) => number_format($record->outstandingRefundableCents() / 100, 2, '.', ''))
                        ->helperText(fn (Order $record) => 'Up to $'
                            .number_format($record->outstandingRefundableCents() / 100, 2)
                            .' is still refundable on this order.'),

                    Textarea::make('reason')->label('Reason')->rows(2)->maxLength(500),

                    TextInput::make('provider_reference')
                        ->label('Provider reference')
                        ->maxLength(255)
                        ->helperText('The refund ID from Stripe, PayPal or your bank, so the two records can be reconciled.'),

                    Toggle::make('restock')
                        ->label('Return items to stock')
                        ->default(true)
                        ->helperText('Turn this off if the goods are not coming back — damaged or a goodwill refund.'),
                ])
                ->action(fn (Order $record, array $data) => self::run(
                    fn () => app(OrderService::class)->refund(
                        $record,
                        (int) round(((float) $data['amount']) * 100),
                        $data['reason'] ?: null,
                        (bool) ($data['restock'] ?? true),
                        $data['provider_reference'] ?: null,
                        auth()->user(),
                    ),
                    'Refund recorded.'
                )),
        ];
    }

    /**
     * OrderService throws rather than silently accepting an illegal change.
     * Surfacing that as a notification is what makes the guard useful to a human
     * instead of a 500 page.
     */
    protected static function run(callable $callback, string $success): void
    {
        try {
            $callback();

            Notification::make()->title($success)->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
