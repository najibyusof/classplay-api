<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    |
    | The gateway used for payment_method = "merchant". No specific provider
    | has been selected yet, so only a generic, configurable adapter exists.
    |
    */

    'default' => env('PAYMENT_GATEWAY', 'merchant'),

    /*
    |--------------------------------------------------------------------------
    | Gateway Credentials
    |--------------------------------------------------------------------------
    |
    | Never hard-code credentials. Everything here is read from the
    | environment so secrets never live in source control.
    |
    */

    'gateways' => [
        'merchant' => [
            'merchant_id' => env('PAYMENT_GATEWAY_MERCHANT_ID'),
            'api_key' => env('PAYMENT_GATEWAY_API_KEY'),
            'secret' => env('PAYMENT_GATEWAY_SECRET'),
            'url' => env('PAYMENT_GATEWAY_URL'),
            'timeout' => (int) env('PAYMENT_GATEWAY_TIMEOUT', 10),
        ],
    ],

];
