<?php

namespace App\Filament\Widgets;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A merged feed of what has happened in the store lately.
 *
 * There is no activity_log table, and adding one to power a dashboard panel
 * would mean writing a row on every save for the sake of a sidebar. The events
 * worth showing are already recorded by the tables that own them — the
 * inventory ledger, order status history, orders and users — so this reads the
 * last few from each and merges them by time.
 *
 * The cost of that is one small query per source, which is why the per-source
 * limit is deliberately tight: five sources x 6 rows beats a UNION that has to
 * be maintained in SQL every time an event type is added.
 */
class RecentActivity extends Widget
{
    protected string $view = 'filament.widgets.recent-activity';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    /** How many rows to pull from each source before merging. */
    private const PER_SOURCE = 6;

    /** How many survive the merge. */
    private const SHOWN = 6;

    /**
     * @return Collection<int, array{icon:string, colour:string, title:string, subtitle:?string, at:Carbon}>
     */
    public function getActivity(): Collection
    {
        return collect()
            ->concat($this->orders())
            ->concat($this->fulfilments())
            ->concat($this->customers())
            ->concat($this->stockChanges())
            ->sortByDesc('at')
            ->take(self::SHOWN)
            ->values();
    }

    private function orders(): Collection
    {
        return Order::query()
            ->whereNotNull('placed_at')
            ->latest('placed_at')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (Order $o) => [
                'icon' => 'heroicon-o-shopping-cart',
                'colour' => 'text-primary-600 dark:text-primary-400',
                'title' => 'New order received',
                'subtitle' => trim($o->order_number.' from '.($o->user?->name ?? $o->email ?? 'a guest')),
                'at' => $o->placed_at,
            ]);
    }

    private function fulfilments(): Collection
    {
        return OrderStatusHistory::query()
            ->with('order')
            ->whereIn('to_status', [Order::STATUS_SHIPPED, Order::STATUS_COMPLETED])
            ->latest('created_at')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (OrderStatusHistory $h) => [
                'icon' => 'heroicon-o-truck',
                'colour' => 'text-success-600 dark:text-success-400',
                'title' => 'Order fulfilled',
                'subtitle' => $h->order?->order_number,
                'at' => $h->created_at,
            ])
            ->filter(fn (array $row) => filled($row['subtitle']));
    }

    private function customers(): Collection
    {
        return User::query()
            ->latest('created_at')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (User $u) => [
                'icon' => 'heroicon-o-user-plus',
                'colour' => 'text-info-600 dark:text-info-400',
                'title' => 'Customer account created',
                'subtitle' => $u->name ?: $u->email,
                'at' => $u->created_at,
            ]);
    }

    private function stockChanges(): Collection
    {
        return InventoryMovement::query()
            ->with('variant.product')
            ->latest('created_at')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (InventoryMovement $m) => [
                'icon' => 'heroicon-o-archive-box',
                'colour' => 'text-warning-600 dark:text-warning-400',
                // Opening stock is named for what it is rather than reported as
                // a change: a variant going from nothing to its first count is
                // not the same event as someone recounting a shelf.
                'title' => $m->reason === InventoryMovement::REASON_INITIAL
                    ? 'Opening stock recorded'
                    : 'Stock level changed',
                'subtitle' => $m->variant?->product?->name
                    ? $m->variant->product->name.' — '.$m->variant->sku
                    : null,
                'at' => $m->created_at,
            ])
            ->filter(fn (array $row) => filled($row['subtitle']));
    }
}
