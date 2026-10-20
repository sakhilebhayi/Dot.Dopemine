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

    // Expiry warnings go to Dot.Notify's ingest API (server to server). The URL and the secret must both be set or
    // nothing is sent. The secret is the value of NOTIFY_INGEST_SECRET_DOPEMINE in Dot.Notify's .env; the platform key
    // is how Notify knows which secret to check.
    'notify' => [
        'url' => env('NOTIFY_INGEST_URL'),
        'secret' => env('NOTIFY_INGEST_SECRET'),
        'platform' => env('NOTIFY_PLATFORM_KEY', 'dopemine'),
        'timeout' => 5,
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
    ],

];
