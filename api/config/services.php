<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/api/v1/auth/google/callback'),
    ],

    /*
    | Address lookup at checkout. `provider` picks one of two, and each needs
    | only its own credential:
    |
    |   mapbox — `mapbox_token` is a PUBLIC token (`pk.…`). It is handed to the
    |            browser, which talks to Mapbox directly through Mapbox's SDK,
    |            so restrict it by URL in the Mapbox account. A secret token
    |            here is refused rather than published.
    |
    |   google — `google_key` is a Places API (New) key from the same Google
    |            Cloud project as the sign-in pair above. It is used from this
    |            server only and never reaches the browser, so restrict it by
    |            API and by the server's addresses, not by website.
    |
    | With the chosen provider's credential unset the lookup is simply off and
    | the address form is typed by hand — nothing else at checkout depends on it.
    */
    'address_lookup' => [
        'provider' => env('ADDRESS_LOOKUP_PROVIDER', 'mapbox'),
        'mapbox_token' => env('MAPBOX_PUBLIC_TOKEN'),
        'google_key' => env('GOOGLE_PLACES_API_KEY'),
        'timeout' => (int) env('ADDRESS_LOOKUP_TIMEOUT', 4),
    ],

    /*
    | Apple sign-in was dropped from scope on 2026-09-04 — a paid developer
    | account and a six-monthly secret rotation for a second button. The brief
    | called it optional. Google is the only social provider.
    */

    /*
    | Stallion Express live shipping rates (§5). Unset until the client
    | supplies an account and API key; ShippingService falls back to table
    | rates while that is the case.
    */
    'stallion' => [
        'key' => env('STALLION_API_KEY'),
        'base_url' => env('STALLION_BASE_URL', 'https://ship.stallionexpress.ca/api/v4'),
        'timeout' => (int) env('STALLION_TIMEOUT', 6),
    ],

    /*
    | Stripe (§4). Three keys, and all three matter for different reasons:
    |
    |   key        — publishable. Safe in a browser; it is handed to the
    |                storefront by the API rather than baked into the Nuxt
    |                build, so rotating it is a restart and not a redeploy.
    |   secret     — server only. Never leaves this process.
    |   webhook    — the signing secret for the endpoint. Without it a webhook
    |                is an unauthenticated POST that can mark orders paid, so
    |                StripeGateway refuses to verify when it is unset rather
    |                than trusting the payload.
    |
    | `STRIPE_KEY` beginning `pk_test_` is what keeps a staging deployment from
    | charging a real card; the admin panel surfaces which mode is live.
    */
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'timeout' => (int) env('STRIPE_TIMEOUT', 20),
    ],

    /*
    | PayPal Checkout (§4).
    |
    | `mode` selects the environment, and the credential pair must belong to
    | that environment — a live client ID is rejected by the sandbox API and
    | vice versa. Nothing here is enough to take a payment on its own: the
    | "Accept PayPal" switch in Store settings is the second gate, so a
    | configured live key sitting in .env cannot start charging customers
    | until an administrator deliberately turns it on.
    |
    | `webhook_id` comes from the webhook you create in the PayPal developer
    | dashboard. Without it the webhook endpoint refuses every delivery rather
    | than trusting an unverified one.
    */
    'paypal' => [
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        'timeout' => (int) env('PAYPAL_TIMEOUT', 20),
        'base_url' => env('PAYPAL_MODE', 'sandbox') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com',
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
