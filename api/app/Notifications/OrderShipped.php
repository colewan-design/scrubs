<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your order is on its way" (§10) — or, for a pickup order, that it is ready
 * to collect. One notification covers both because to the customer they are the
 * same event: the thing they bought is now available to them.
 */
class OrderShipped extends OrderNotification
{
    public function __construct(
        public Order $order,
        public ?Shipment $shipment = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;

        if ($order->isPickup()) {
            return (new MailMessage)
                ->subject("Order {$order->order_number} is ready to collect")
                ->greeting('Ready for collection')
                ->line("Order {$order->order_number} is packed and waiting for you.")
                ->action('View your order', $order->storefrontUrl())
                ->salutation('— BulkScrubs Direct');
        }

        $shipment = $this->shipment ?? $order->shipments()->latest('id')->first();

        $mail = (new MailMessage)
            ->subject("Order {$order->order_number} has shipped")
            ->greeting('On its way')
            ->line("Order {$order->order_number} left us today.");

        if ($shipment?->carrier) {
            $mail->line("Carrier: {$shipment->carrier}");
        }

        if ($shipment?->tracking_number) {
            $mail->line("Tracking number: {$shipment->tracking_number}");
        }

        // Prefer the carrier's own tracking page; fall back to the order page,
        // which shows the same number.
        return $mail
            ->action(
                $shipment?->tracking_url ? 'Track your parcel' : 'View your order',
                $shipment?->tracking_url ?: $order->storefrontUrl(),
            )
            ->salutation('— BulkScrubs Direct');
    }
}
