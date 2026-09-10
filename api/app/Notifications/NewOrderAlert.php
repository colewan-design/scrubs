<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Administrator alert for a new order (§10).
 *
 * Deliberately terse and factual — it exists so nobody has to keep the admin
 * dashboard open to notice that an order arrived.
 */
class NewOrderAlert extends Notification
{
    use Queueable;

    public function __construct(public Order $order) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

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
