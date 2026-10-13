<?php

namespace App\Services\Payments;

/**
 * The provider already holds the money for this payment; only our record of it
 * has not caught up yet.
 *
 * Its own type for the reason PayPalPaymentPending has one: it needs the
 * opposite said to the customer. Every other PaymentException invites another
 * attempt, and this one must not — they have paid, and the webhook that marks
 * the order is on its way.
 */
class PaymentAlreadyReceived extends PaymentException {}
