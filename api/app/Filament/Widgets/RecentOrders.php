<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The last few orders through the door, as a glance rather than a workspace.
 *
 * No filters, no search, no row selection: anything beyond "what just came in"
 * belongs on the Orders screen, and the heading action goes straight there.
 */
class RecentOrders extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    public function getTableHeading(): string
    {
        return 'Recent orders';
    }

    protected function getTableHeaderActions(): array
    {
        return [
            Action::make('viewAll')
                ->label('View all orders')
                ->icon('heroicon-m-arrow-right')
                ->iconPosition('after')
                ->link()
                ->url(OrderResource::getUrl('index')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()
                    ->with('user')
                    // placed_at is null for a basket that never completed, and
                    // those are not orders anyone wants surfaced here.
                    ->whereNotNull('placed_at')
                    ->latest('placed_at')
                    ->limit(5)
            )
            ->paginated(false)
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('order_number')
                    ->label('#')
                    ->weight('medium'),

                TextColumn::make('customer')
                    ->label('Customer')
                    // Guest checkout is allowed, so there is not always a user
                    // row to read a name from; the order's own email is the
                    // one field always present.
                    ->state(fn (Order $record): string => $record->user?->name
                        ?? $record->email
                        ?? 'Guest')
                    ->limit(28),

                TextColumn::make('grand_total_cents')
                    ->label('Total')
                    ->money('CAD', divideBy: 100),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Order::STATUS_LABELS[$state] ?? $state)
                    // Same mapping as the Orders table: one status should not
                    // be amber on one screen and grey on another.
                    ->color(fn (string $state): string => match ($state) {
                        Order::STATUS_PENDING_PAYMENT => 'warning',
                        Order::STATUS_CANCELLED, Order::STATUS_REFUNDED => 'danger',
                        Order::STATUS_COMPLETED => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('placed_at')
                    ->label('Date')
                    ->date('M j, Y')
                    ->color('gray'),
            ])
            ->emptyStateHeading('No orders yet')
            ->emptyStateDescription('Orders will appear here as soon as the first one is placed.')
            ->emptyStateIcon('heroicon-o-shopping-bag');
    }
}
