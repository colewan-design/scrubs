<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * The password reset email (§8).
 *
 * Laravel's default points at a server-rendered route. This storefront is a
 * Nuxt SPA, so the link has to reach the frontend's own reset page carrying the
 * token and email — otherwise the customer lands on a 404 in the API.
 */
class ResetPasswordLink extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(Config::get('app.frontend_url'), '/')
            .'/account/reset-password?'
            .http_build_query([
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);

        $minutes = Config::get('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your BulkScrubs Direct password')
            ->greeting('Password reset')
            ->line('We received a request to reset the password for this account.')
            ->action('Choose a new password', $url)
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you did not ask for this, you can ignore this email — your password will not change.')
            ->salutation('— BulkScrubs Direct');
    }
}
