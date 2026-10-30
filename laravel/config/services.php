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

    'd365' => [
        'enabled' => (bool) env('D365_ENABLED', false),
        'environment_url' => env('D365_ENVIRONMENT_URL'),
        'tenant_id' => env('D365_TENANT_ID'),
        'client_id' => env('D365_CLIENT_ID'),
        'client_secret' => env('D365_CLIENT_SECRET'),
        'company' => env('D365_COMPANY', 'BP'),
        'entity_name' => env('D365_ENTITY_NAME', 'GeneralJournalAccountEntryBiEntities'),
        'sync_interval_minutes' => (int) env('D365_SYNC_INTERVAL_MINUTES', 15),
        'auto_sync' => (bool) env('D365_AUTO_SYNC', false),
    ],

];
