<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\Support\CsvExportAction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PayPalService;
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
            // The refund action asks every row how it was paid. Loaded here so
            // that is one query for the page rather than one per order.
            ->modifyQueryUsing(fn ($query) => $query->with('payments'))
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

            /*
             * Refunding does three different jobs depending on how the order was
             * paid, and the modal says which one is about to happen rather than
             * leaving the administrator to work it out:
             *
             *   CARD   — Stripe is called and the money actually goes back.
             *
             *   PAYPAL — PayPal is called the same way. The provider call goes
             *            in front of OrderService::refund(), which still writes
             *            the ledger row and returns the stock.
             *
             *   OTHER  — e-Transfer and manual payments move money somewhere
             *            this system cannot reach, so the administrator sends it
             *            by hand first and records it here afterwards.
             *
             * In the first two there is no field to type a reference into: the
             * provider issues it, and inventing one would be the only way to get
             * it wrong. "Record a refund" and "refund the customer" are very
             * different actions to take by mistake, hence the wording changes.
             */
            Action::make('refund')
                ->label(fn (Order $record) => self::refundsAutomatically($record) ? 'Refund' : 'Record refund')
                ->icon('heroicon-o-receipt-refund')
                ->color('warning')
                ->visible(fn (Order $record) => in_array($record->payment_status, [
                    Order::PAYMENT_PAID,
                    Order::PAYMENT_PARTIALLY_REFUNDED,
                ], true) && $record->outstandingRefundableCents() > 0)
                ->modalHeading(fn (Order $record) => self::refundsAutomatically($record)
                    ? "Refund — {$record->order_number}"
                    : "Record refund — {$record->order_number}")
                ->modalDescription(fn (Order $record) => match (true) {
                    self::refundsViaPayPal($record) => 'This sends the refund through PayPal now and returns the money to the customer. It cannot be undone.',
                    self::refundsThroughGateway($record) => 'This sends the money back through Stripe now. It cannot be undone.',
                    default => 'Refund the customer through your payment provider first, then record it here.',
                })
                ->modalSubmitActionLabel(fn (Order $record) => self::refundsAutomatically($record)
                    ? 'Send refund'
                    : 'Record refund')
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

                    // Hidden whenever the provider issues the reference itself —
                    // a typed-in one would just be a second, unreliable record
                    // of the same thing.
                    TextInput::make('provider_reference')
                        ->label('Provider reference')
                        ->maxLength(255)
                        ->visible(fn (Order $record) => ! self::refundsAutomatically($record))
                        ->helperText('The refund ID from your bank or provider, so the two records can be reconciled.'),

                    Toggle::make('restock')
                        ->label('Return items to stock')
                        ->default(true)
                        ->helperText('Turn this off if the goods are not coming back — damaged or a goodwill refund.'),
                ])
                ->action(function (Order $record, array $data) {
                    $amountCents = (int) round(((float) $data['amount']) * 100);
                    $reason = $data['reason'] ?: null;
                    $restock = (bool) ($data['restock'] ?? true);

                    // PayPal has its own service rather than a PaymentGateway,
                    // so it is dispatched before the generic path. The two
                    // predicates cannot both be true: PaymentService registers
                    // Stripe only.
                    if (self::refundsViaPayPal($record)) {
                        self::run(
                            fn () => app(PayPalService::class)->refund(
                                $record, $amountCents, $reason, $restock, auth()->user(),
                            ),
                            'Refunded through PayPal and recorded.'
                        );

                        return;
                    }

                    $throughGateway = self::refundsThroughGateway($record);

                    self::run(
                        fn () => app(OrderService::class)->refund(
                            $record,
                            $amountCents,
                            $reason,
                            $restock,
                            $data['provider_reference'] ?? null ?: null,
                            auth()->user(),
                            $throughGateway,
                        ),
                        $throughGateway ? 'Refund sent.' : 'Refund recorded.'
                    );
                }),
        ];
    }

    /** @var array<int, bool> Per-request memo — see refundsThroughGateway(). */
    protected static array $gatewayRefundable = [];

    /**
     * Whether this order's money can be sent back by API, or has to go by hand.
     *
     * Reads the payment that was actually taken, not the store's current
     * settings: an order paid by card last month is still refundable through
     * Stripe even if cards have since been switched off at checkout.
     *
     * Memoised because Filament evaluates a row action's label, visibility and
     * three modal closures separately for every row on the page. Without the
     * cache that is five queries per order, twenty orders at a time, to answer
     * the same question each time.
     */
    protected static function refundsThroughGateway(Order $record): bool
    {
        return self::$gatewayRefundable[$record->id] ??= (function () use ($record): bool {
            $payment = $record->payments
                ->firstWhere('status', Payment::STATUS_SUCCEEDED)
                ?? $record->payments->last();

            return $payment !== null
                && $payment->provider_reference !== null
                && app(PaymentService::class)->isGateway($payment->provider);
        })();
    }

    /**
     * Whether the refund button moves money, rather than recording money the
     * administrator has already moved by hand.
     *
     * True for either provider. Kept separate from the two predicates below
     * because the wording of the button, the modal and the reference field all
     * turn on this one question, while only the action itself needs to know
     * which provider is about to be called.
     */
    protected static function refundsAutomatically(Order $record): bool
    {
        return self::refundsViaPayPal($record) || self::refundsThroughGateway($record);
    }

    /**
     * Whether this order can be refunded through PayPal, which needs a captured
     * PayPal payment to refund against — not merely an order that was *going* to
     * be paid that way.
     */
    protected static function refundsViaPayPal(Order $order): bool
    {
        return $order->payments
            ->where('provider', Payment::PROVIDER_PAYPAL)
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->contains(fn (Payment $payment) => $payment->paypalCaptureId() !== null);
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
