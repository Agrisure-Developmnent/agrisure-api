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
    'semaphore' => [
        'api_key' => env('SEMAPHORE_API_KEY'),
        'sender_name' => env('SEMAPHORE_SENDER_NAME', 'AgriSure'),
    ],

    /*
    | Fixed OTP for load/automation testing (e.g. JMeter). Only the accounts
    | listed in OTP_BYPASS_ACCOUNTS (comma-separated emails or phone numbers)
    | get OTP_BYPASS_CODE; everyone else always gets a random OTP.
    | Leave OTP_BYPASS_CODE empty to turn the bypass off completely.
    */
    'otp_bypass' => [
        'code' => env('OTP_BYPASS_CODE'),
        'accounts' => array_values(array_filter(array_map(
            fn ($v) => strtolower(trim($v)),
            explode(',', (string) env('OTP_BYPASS_ACCOUNTS', ''))
        ))),
    ],

];
