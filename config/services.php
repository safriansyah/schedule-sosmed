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

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Unofficial Instagram comment viewer (dolphinradar). The official Graph
    | API only returns comment text once the app has Advanced Access, so this
    | public viewer is used as a best-effort fallback to read comments on our
    | own posts. It is unofficial and may change or stop without notice — the
    | sync is written to fail quietly and never break the app.
    */
    'dolphinradar' => [
        'base_url' => env('DOLPHINRADAR_URL', 'https://www.dolphinradar.com'),
        'tenant_id' => env('DOLPHINRADAR_TENANT', '6'),
        'timezone' => env('DOLPHINRADAR_TZ', 'Asia/Jakarta'),
        // Only sync comments for posts newer than this many days (comments
        // arrive mostly on recent posts); 0 = no age limit.
        'max_age_days' => (int) env('DOLPHINRADAR_MAX_AGE_DAYS', 120),
        // Hard cap on posts hit per account per run, to stay polite.
        'max_posts' => (int) env('DOLPHINRADAR_MAX_POSTS', 40),
    ],

];
