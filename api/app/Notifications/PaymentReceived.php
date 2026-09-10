<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Your payment cleared" (§10). Sent when an order moves to Paid. */
class PaymentReceived extends Notification
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

        return (new MailMessage)
            ->subject("Payment received for order {$order->order_number}")
            ->greeting('Payment received')
            ->line('We have received your payment of **$'
                .number_format($order->grand_total_cents / 100, 2).' CAD** '
                ."for order {$order->order_number}.")
            ->line($order->isPickup()
                ? 'We are preparing your order and will tell you when it is ready to collect.'
                : 'We are preparing your order and will send tracking details once it ships.')
            ->action('View your order', url("/orders/{$order->order_number}"))
            ->salutation('— BulkScrubs Direct');
    }
}
