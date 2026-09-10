<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "We have your order" (§10).
 *
 * Sent the moment an order is placed, before payment. While settlement is
 * manual this email is also where the customer learns how to pay, so the
 * e-Transfer instructions travel with it rather than in a second message.
 */
class OrderConfirmation extends Notification
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
        $settings = app(Settings::class);
        $order = $this->order;

        $mail = (new MailMessage)
            ->subject("Order {$order->order_number} received")
            ->greeting('Thank you for your order')
            ->line("We have your order **{$order->order_number}**, placed on "
                .$order->placed_at?->format('j F Y').'.')
            ->line('**Total: '.$this->money($order->grand_total_cents).' CAD**');

        foreach ($order->items as $item) {
            $mail->line("{$item->qty} × {$item->product_name} — "
                .$this->money($item->line_total_cents));
        }

        if ($order->isPickup()) {
            $mail->line('You have chosen local pickup. We will let you know as soon as your order is ready to collect.');

            if ($address = $settings->string('pickup.address')) {
                $mail->line("Collection address: {$address}");
            }
        } else {
            $mail->line('We will email tracking details as soon as your order ships.');
        }

        // Payment is settled out of band until a processor is live, so the
        // instructions are part of the confirmation rather than a separate mail.
        if ($order->payment_status === Order::PAYMENT_PENDING) {
            $mail->line('**This order is awaiting payment.**');

            if ($instructions = $settings->string('orders.etransfer_instructions')) {
                $mail->line($instructions);
            }
        }

        // §6 — this email is the receipt, so it carries the seller's tax
        // registration number when tax was charged under one.
        if ($registration = ($order->tax_registration ?? $settings->string('tax.gst_number'))) {
            $mail->line("GST/HST registration: {$registration}");
        }

        return $mail
            ->action('View your order', url("/orders/{$order->order_number}"))
            ->salutation('— BulkScrubs Direct');
    }

    protected function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}
