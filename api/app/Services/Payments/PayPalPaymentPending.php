<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * PayPal accepted the payment but has not cleared it: an eCheck, or a capture
 * held for review.
 *
 * Separate from a plain RuntimeException because the two need opposite things
 * said to the customer. A failure invites another attempt; this must not, since
 * the money is already on its way. `PAYMENT.CAPTURE.COMPLETED` confirms the
 * order if and when it clears.
 */
class PayPalPaymentPending extends RuntimeException {}
