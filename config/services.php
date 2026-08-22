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

    'viva' => [
        'enabled' => filter_var(env('VIVA_ENABLED', false), FILTER_VALIDATE_BOOL),
        'client_id' => env('VIVA_CLIENT_ID'),
        'client_secret' => env('VIVA_CLIENT_SECRET'),
        'source_code' => env('VIVA_SOURCE_CODE'),
        'environment' => env('VIVA_ENVIRONMENT', 'demo'),
        'webhook_verification_key' => env('VIVA_WEBHOOK_VERIFICATION_KEY'),
        // Optional HTTP Basic credentials, set on the webhook in the Viva
        // dashboard. Leaving either blank keeps the endpoint anonymous, so an
        // existing deployment is not cut off by upgrading.
        'webhook_username' => env('VIVA_WEBHOOK_USERNAME'),
        'webhook_password' => env('VIVA_WEBHOOK_PASSWORD'),
    ],

];
