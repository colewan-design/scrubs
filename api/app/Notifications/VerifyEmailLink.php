<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The address-confirmation email (§8).
 *
 * The link is a signed API URL rather than a storefront one. The signature is
 * the proof — it is HMAC'd with the application key and carries an expiry — so
 * the customer can click it from their phone, from webmail, or from a browser
 * that has never held a session for this site, and it still works.
 */
class VerifyEmailLink extends Notification
{
    use Queueable;

    public function __construct(public string $url, public int $expiresInMinutes) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your email — BulkScrubs Direct')
            ->greeting('One quick confirmation')
            ->line('Confirm this address so we can send you order confirmations and tracking.')
            ->action('Confirm my email', $this->url)
            ->line("This link expires in {$this->expiresInMinutes} minutes.")
            ->line('Your account already works — wholesale pricing is unlocked whether or not you confirm.')
            ->line('If you did not create an account with us, you can ignore this email.')
            ->salutation('— BulkScrubs Direct');
    }
}
