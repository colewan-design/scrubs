<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * What every order email has in common (§10): it goes by mail, and it goes
 * from the queue.
 *
 * Queued, because sent inline the customer waits on a conversation with the
 * mail host before their own order answers — two of them at checkout, one for
 * the confirmation and one for the shop's alert. A slow host made checkout
 * slow, and a host that hung held it open until the socket gave up. The order
 * is the thing that matters; its email can follow a second behind.
 *
 * Tried three times, because a mail host that is down is usually back within
 * minutes and a confirmation that arrives five minutes late is still worth
 * having. One that fails all three lands in failed_jobs, where it can be seen
 * and retried, rather than vanishing into a log line.
 */
abstract class OrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * A minute, then five.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }
}
