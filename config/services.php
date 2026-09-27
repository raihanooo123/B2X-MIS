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

    // 05.12 §6.1: email delivery. Webhooks authenticate by HTTP basic auth.
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
        'webhook_user' => env('POSTMARK_WEBHOOK_USER'),
        'webhook_password' => env('POSTMARK_WEBHOOK_PASSWORD'),
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

    // 07 §6.4: card payments through Stripe Elements (SAQ-A). `key` is the
    // publishable key the browser uses; `secret` and `webhook_secret`
    // never leave the server.
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // 02 §25.4–25.5, §25.10: trade application verification. Missing
    // credentials never block an application: checks record `unchecked`
    // with `not_configured`. Every request times out after 5 seconds.
    'hmrc_vat' => [
        // Sandbox: https://test-api.service.hmrc.gov.uk
        'base_url' => env('HMRC_API_BASE_URL', 'https://api.service.hmrc.gov.uk'),
        'client_id' => env('HMRC_CLIENT_ID'),
        'client_secret' => env('HMRC_CLIENT_SECRET'),
    ],

    'vies' => [
        'base_url' => env('VIES_API_BASE_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api'),
    ],

    'companies_house' => [
        'base_url' => env('COMPANIES_HOUSE_API_BASE_URL', 'https://api.company-information.service.gov.uk'),
        'key' => env('COMPANIES_HOUSE_API_KEY'),
    ],

    'verification' => [
        'timeout_seconds' => 5,
    ],

];
