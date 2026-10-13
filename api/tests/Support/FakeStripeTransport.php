<?php

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;

/**
 * Stripe's HTTP transport with the network removed — one layer below
 * FakeStripeGateway.
 *
 * FakeStripeGateway replaces the gateway's own methods, which is right for
 * testing everything around the gateway and no use for testing the gateway
 * itself. This sits underneath the SDK instead: StripeGateway and stripe-php
 * both run for real, and only the socket is pretend. That is the seam the first
 * real checkout failed in, with every test above it passing.
 *
 * It knows one payment intent. Whatever is asked of it, it answers with that
 * intent in whichever state the test has put it — unless the test has given it
 * a different answer for that request.
 */
class FakeStripeTransport implements ClientInterface
{
    public const INTENT = 'pi_fake';

    /** @var list<string> "POST /v1/payment_intents", in the order they were made. */
    public array $requests = [];

    public string $intentStatus = 'requires_payment_method';

    /** Extra response headers, for the things Stripe says outside the body. */
    public array $headers = [];

    /** Extra fields for the one intent — an expanded `latest_charge`, say. */
    public array $intentExtras = [];

    /**
     * Whole answers for anything that is not the intent, keyed the way
     * `$requests` records them ("GET /v1/refunds").
     *
     * @var array<string, array>
     */
    public array $answers = [];

    protected int $amount = 0;

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $request = strtoupper($method).' '.parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = $request;

        // The amount an intent was opened for is the amount it keeps.
        if (isset($params['amount'])) {
            $this->amount = (int) $params['amount'];
        }

        $body = $this->answers[$request] ?? [
            'id' => self::INTENT,
            'object' => 'payment_intent',
            'amount' => $this->amount,
            'currency' => 'cad',
            'status' => $this->intentStatus,
            'client_secret' => self::INTENT.'_secret_test',
        ] + $this->intentExtras;

        return [
            json_encode($body),
            200,
            new CaseInsensitiveArray(['Request-Id' => 'req_test'] + $this->headers),
        ];
    }
}
