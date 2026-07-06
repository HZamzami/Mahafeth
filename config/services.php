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

    'fx' => [
        // exchangerate-api.com open endpoint: keyless, daily refresh.
        'base_url' => env('FX_API_BASE_URL', 'https://open.er-api.com'),
    ],

    'twelvedata' => [
        'base_url' => env('TWELVEDATA_BASE_URL', 'https://api.twelvedata.com'),
        'key' => env('TWELVEDATA_KEY'),
    ],

    'marketaux' => [
        'base_url' => env('MARKETAUX_BASE_URL', 'https://api.marketaux.com'),
        'token' => env('MARKETAUX_TOKEN'),
    ],

    'alinma' => [
        'base_url' => env('ALINMA_OB_BASE_URL'),
        'client_id' => env('ALINMA_OB_CLIENT_ID'),
        'client_secret' => env('ALINMA_OB_CLIENT_SECRET'),
        'cert_path' => env('ALINMA_OB_CERT_PATH'),
        'key_path' => env('ALINMA_OB_KEY_PATH'),
    ],

];
