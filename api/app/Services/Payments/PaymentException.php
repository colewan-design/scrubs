<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * A payment provider said no, or could not be reached.
 *
 * Separate from the generic RuntimeException the order code throws so that
 * checkout can tell the two apart: a declined card is the customer's problem to
 * fix on the form, an unreachable provider is ours, and they deserve different
 * words on the screen.
 */
class PaymentException extends RuntimeException
{
    public static function notConfigured(string $provider): self
    {
        return new self("The {$provider} payment provider is not configured.");
    }
}
