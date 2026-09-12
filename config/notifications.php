<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Push Notification Configuration (FCM)
    |--------------------------------------------------------------------------
    |
    | Credentials for Firebase Cloud Messaging (FCM). Never hard-code secrets
    | in source files — all values must be supplied via environment variables.
    |
    */

    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
        'api_url' => env('FCM_API_URL', 'https://fcm.googleapis.com/fcm/send'),
        'timeout' => (int) env('FCM_TIMEOUT', 10),
    ],

];
