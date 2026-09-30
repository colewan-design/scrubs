<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The PayPal REST transport — tokens, HTTP and nothing else (§4).
 *
 * Deliberately thin: it knows how to authenticate and how to reach v2 Orders,
 * and it has no opinion about our orders, prices or statuses. PayPalService
 * holds all of that. Keeping the split means the money logic is testable by
 * faking HTTP rather than by mocking a gateway object.
 *
 * TWO THINGS WORTH KNOWING
 *
 *  1. The access token is cached. PayPal issues nine-hour tokens and rate-limits
 *     the token endpoint, so minting one per API call is both slow and a way to
 *     get throttled mid-checkout. It is cached for slightly less than PayPal's
 *     own expiry so a token can never be used in the second it dies.
 *
 *  2. Nothing here throws on an HTTP error. Every method returns the Response
 *     and lets the caller decide, because "PayPal said no" is a message for the
 *     customer, not a 500.
 */
class PayPalClient
{
    protected const TOKEN_CACHE_KEY = 'paypal.access_token';

    /** Both halves of the pair plus a mode are needed before any call is possible. */
    public function configured(): bool
    {
        return (bool) config('services.paypal.client_id')
            && (bool) config('services.paypal.client_secret');
    }

    public function mode(): string
    {
        return config('services.paypal.mode') === 'live' ? 'live' : 'sandbox';
    }

    public function isLive(): bool
    {
        return $this->mode() === 'live';
    }

    public function clientId(): ?string
    {
        return config('services.paypal.client_id');
    }

    /**
     * Create a PayPal order. `$requestId` is sent as PayPal-Request-Id, which
     * makes the call idempotent: a retry after a timeout returns the order that
     * was already created rather than creating a second one.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createOrder(array $payload, string $requestId): ?Response
    {
        return $this->send(
            fn (PendingRequest $http) => $http
                ->withHeader('PayPal-Request-Id', $requestId)
                ->post('/v2/checkout/orders', $payload),
            'create order',
        );
    }

    public function getOrder(string $paypalOrderId): ?Response
    {
        return $this->send(
            fn (PendingRequest $http) => $http->get("/v2/checkout/orders/{$paypalOrderId}"),
            'get order',
        );
    }

    /**
     * Capture an approved order. Idempotent on PayPal-Request-Id for the same
     * reason as creation — and here it matters more, because a retried capture
     * without it is a second charge.
     */
    public function captureOrder(string $paypalOrderId, string $requestId): ?Response
    {
        return $this->send(
            fn (PendingRequest $http) => $http
                ->withHeader('PayPal-Request-Id', $requestId)
                // PayPal requires a body on this call; an empty object is the
                // documented way to say "capture the full authorised amount".
                ->post("/v2/checkout/orders/{$paypalOrderId}/capture", new \stdClass),
            'capture order',
        );
    }

    /** Refund a capture. `$amount` is null for a full refund. */
    public function refundCapture(
        string $captureId,
        ?string $amount,
        string $currency,
        string $requestId,
        ?string $note = null,
    ): ?Response {
        $payload = [];

        if ($amount !== null) {
            $payload['amount'] = ['value' => $amount, 'currency_code' => $currency];
        }

        if ($note) {
            // PayPal shows this to the buyer on their refund receipt.
            $payload['note_to_payer'] = mb_substr($note, 0, 255);
        }

        return $this->send(
            fn (PendingRequest $http) => $http
                ->withHeader('PayPal-Request-Id', $requestId)
                ->post("/v2/payments/captures/{$captureId}/refund", $payload ?: new \stdClass),
            'refund capture',
        );
    }

    /**
     * Ask PayPal whether a webhook delivery really came from PayPal.
     *
     * Verifying server-side rather than checking the certificate chain
     * ourselves: it is one call, and it cannot be got subtly wrong in a way
     * that accepts forged events.
     *
     * @param  array<string, string>  $headers
     */
    public function verifyWebhookSignature(array $headers, array $event, string $webhookId): ?Response
    {
        return $this->send(
            fn (PendingRequest $http) => $http->post('/v1/notifications/verify-webhook-signature', [
                'transmission_id' => $headers['transmission_id'] ?? '',
                'transmission_time' => $headers['transmission_time'] ?? '',
                'cert_url' => $headers['cert_url'] ?? '',
                'auth_algo' => $headers['auth_algo'] ?? '',
                'transmission_sig' => $headers['transmission_sig'] ?? '',
                'webhook_id' => $webhookId,
                'webhook_event' => $event,
            ]),
            'verify webhook',
        );
    }

    // ---------------------------------------------------------------- internals

    /**
     * One place where every call is made, so the token, the base URL, the
     * timeout and the failure log are not repeated five times.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    protected function send(callable $call, string $what): ?Response
    {
        if (! $this->configured()) {
            return null;
        }

        $token = $this->accessToken();

        if (! $token) {
            return null;
        }

        try {
            $response = $call($this->http()->withToken($token));
        } catch (\Throwable $e) {
            Log::error("PayPal {$what} threw.", ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            // The body carries PayPal's own `name`/`details`, which is the only
            // thing that makes a 422 from them diagnosable after the fact.
            Log::warning("PayPal {$what} failed.", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $response;
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(rtrim(config('services.paypal.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.paypal.timeout', 20));
    }

    /**
     * A cached client-credentials token.
     *
     * Cached under a key that includes the mode and client id, so switching
     * between sandbox and live — or rotating the secret — cannot hand a call
     * the previous environment's token.
     */
    public function accessToken(): ?string
    {
        $cacheKey = self::TOKEN_CACHE_KEY.':'.$this->mode().':'.substr(
            hash('sha256', (string) config('services.paypal.client_id')), 0, 12
        );

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::baseUrl(rtrim(config('services.paypal.base_url'), '/'))
                ->acceptJson()
                ->asForm()
                ->timeout((int) config('services.paypal.timeout', 20))
                ->withBasicAuth(
                    (string) config('services.paypal.client_id'),
                    (string) config('services.paypal.client_secret'),
                )
                ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        } catch (\Throwable $e) {
            Log::error('PayPal token request threw.', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('PayPal token request failed.', [
                'status' => $response->status(),
                // Never the body: on a bad-credentials 401 it can echo back
                // parts of what was sent.
                'error' => $response->json('error'),
                'mode' => $this->mode(),
            ]);

            return null;
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            return null;
        }

        // 60s of headroom, floored at a minute, so a token is never used in the
        // moment it expires and a short-lived token is still cached usefully.
        $ttl = max(60, (int) $response->json('expires_in', 32400) - 60);

        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    /** Drops the cached token — for use after rotating credentials. */
    public function forgetToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY.':'.$this->mode().':'.substr(
            hash('sha256', (string) config('services.paypal.client_id')), 0, 12
        ));
    }
}
