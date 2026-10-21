<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Stores normally bring their own credentials (payment_gateways table).
    | The platform-level keys below are only used when
    | `use_platform_credentials` is enabled (e.g. a platform-managed account).
    |
    */

    'providers' => [
        'paystack' => [
            'base_url' => 'https://api.paystack.co',
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
        ],
        'flutterwave' => [
            'base_url' => 'https://api.flutterwave.com/v3',
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
            'webhook_secret' => env('FLUTTERWAVE_WEBHOOK_HASH'),
        ],
    ],

    'use_platform_credentials' => (bool) env('PAYMENTS_USE_PLATFORM_CREDENTIALS', false),

    'http_timeout' => 20,

    // A still-pending payment younger than this is reused instead of creating a new attempt.
    'reuse_pending_minutes' => 30,

    // Unpaid PENDING orders are cancelled (stock released) after this many hours. 0 disables.
    'unpaid_order_expiry_hours' => (int) env('UNPAID_ORDER_EXPIRY_HOURS', 24),
];
