<?php

return [

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

    'stripe' => [
        'key' => env('STRIPE_KEY', ''),
        'secret' => env('STRIPE_SECRET', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        'currency' => strtolower((string) env('STRIPE_CURRENCY', 'usd')),
    ],

    'frontend' => [
        'url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),
    ],

    'shipping' => [
        'flat_rate' => (string) env('SHIPPING_FLAT_RATE', '15.00'),
        'free_threshold' => (string) env('SHIPPING_FREE_THRESHOLD', '250.00'),
    ],

];
