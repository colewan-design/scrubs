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
