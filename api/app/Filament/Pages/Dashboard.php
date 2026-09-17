<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Carbon;

/**
 * The store's front page for staff.
 *
 * Two columns rather than the design's three. The inventory table carries eight
 * columns of data — thumbnail, product, variant, SKU, available, on hand,
 * reserved, status — and at two thirds of a 1440 viewport the SKU and status
 * columns start truncating, which is the opposite of what an alerts table is
 * for. Given full width it reads at every breakpoint, and the two smaller
 * panels pair off cleanly on the row beneath.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Dashboard';

    public function getSubheading(): string
    {
        return "Here's what's happening with your store today.";
    }

    /**
     * Widgets place themselves with $columnSpan against this.
     */
    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'lg' => 2,
        ];
    }

    /** Shown top-right, matching the design's dated header. */
    public function getFormattedDate(): string
    {
        return Carbon::now()->format('l, F j, Y');
    }
}
