<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform billing (merchants paying for their plan)
    |--------------------------------------------------------------------------
    |
    | This is the PLATFORM's own Paystack account, separate from the keys each store
    | enters to take payments from its customers. Without a key, trials and
    | downgrades still work but nobody can subscribe.
    |
    */

    'paystack_secret_key' => env('BILLING_PAYSTACK_SECRET_KEY'),

    'currency' => 'NGN',

    /*
    | Billing periods in months => discount percent off the monthly price.
    */
    'periods' => [
        1 => 0,
        3 => 10,
        12 => 20,
    ],

    /*
    | A failed renewal is retried this many days after the period ended, and the
    | store drops to the free plan once `grace_days` have passed without payment.
    */
    'retry_after_days' => [1, 3, 5],
    'grace_days' => 7,

    // Days before a trial ends or a card is charged that the merchant is reminded.
    'reminder_days' => 3,

    'http_timeout' => 20,
];
