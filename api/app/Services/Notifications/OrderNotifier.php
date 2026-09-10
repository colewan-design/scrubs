<?php

namespace App\Services\Notifications;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Notifications\NewOrderAlert;
use App\Notifications\OrderConfirmation;
use App\Notifications\OrderShipped;
use App\Notifications\PaymentReceived;
use App\Support\Settings;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The one place order email is sent from (§10).
 *
 * Two rules, both about not letting email break commerce:
 *
 *  1. Nothing sends unless `notifications.enabled` is on. Email delivery is a
 *     later phase, and the switch means turning it on is a settings change
 *     rather than a deployment.
 *
 *  2. A send that throws is logged and swallowed. A failing SMTP host must
 *     never roll back a paid order or 500 a checkout that has already taken
 *     the customer's money — the order is the thing that matters, the receipt
 *     can be re-sent.
 *
 * Recipients are addressed on-demand rather than through a User, because a
 * guest can check out and still needs their confirmation.
 */
class OrderNotifier
{
    public function __construct(protected Settings $settings) {}

    public function orderPlaced(Order $order): void
    {
        $this->toCustomer($order, new OrderConfirmation($order));
        $this->toAdmin(new NewOrderAlert($order));
    }

    public function paymentReceived(Order $order): void
    {
        $this->toCustomer($order, new PaymentReceived($order));
    }

    public function orderShipped(Order $order, ?Shipment $shipment = null): void
    {
        $this->toCustomer($order, new OrderShipped($order, $shipment));
    }

    public function enabled(): bool
    {
        return $this->settings->bool('notifications.enabled');
    }

    protected function toCustomer(Order $order, BaseNotification $notification): void
    {
        if (! $order->email) {
            return;
        }

        $this->send($order->email, $notification);
    }

    /**
     * Falls back to the first active administrator when no explicit alert
     * address is configured, so the alert still lands somewhere useful.
     */
    protected function toAdmin(BaseNotification $notification): void
    {
        $to = $this->settings->string('notifications.admin_email')
            ?: User::where('role', 'admin')->where('status', 'active')->value('email');

        if (! $to) {
            return;
        }

        $this->send($to, $notification);
    }

    protected function send(string $email, BaseNotification $notification): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            Notification::route('mail', $email)->notify($notification);
        } catch (Throwable $e) {
            // Rule 2. The order has already happened; the email is secondary.
            Log::error('Order notification failed to send.', [
                'notification' => $notification::class,
                'recipient' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
