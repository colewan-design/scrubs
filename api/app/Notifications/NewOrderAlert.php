<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Administrator alert for a new order (§10).
 *
 * Deliberately terse and factual — it exists so nobody has to keep the admin
 * dashboard open to notice that an order arrived.
 */
class NewOrderAlert extends OrderNotification
{
    public function __construct(public Order $order) {}

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;

        $mail = (new MailMessage)
            ->subject("New order {$order->order_number} — $"
                .number_format($order->grand_total_cents / 100, 2))
            ->greeting('New order')
            ->line("**{$order->order_number}** — ".$order->statusLabel())
            ->line('Customer: '.$order->email)
            ->line('Total: $'.number_format($order->grand_total_cents / 100, 2).' CAD')
            ->line('Method: '.($order->isPickup() ? 'Local pickup' : 'Shipping'));

        if ($order->pricing_tier_name) {
            $mail->line('Wholesale tier: '.$order->pricing_tier_name);
        }

        foreach ($order->items as $item) {
            $mail->line("{$item->qty} × {$item->product_name} ({$item->variant_sku})");
        }

        return $mail
            ->action('Open in admin', url('/admin/orders'))
            ->salutation('BulkScrubs Direct');
    }
}
