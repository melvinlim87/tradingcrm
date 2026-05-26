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

    'ea' => [
        'token' => env('EA_PUSH_TOKEN'),
    ],

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'anthropic/claude-3.5-sonnet'),
        'temperature' => env('OPENROUTER_TEMPERATURE', 0.3),
    ],

    // Aisita chart-image API — returns 3 base64 PNG charts (M15 / H1 / H4)
    // for a given symbol. Used as the primary source of charts for the AI
    // analysis pipeline (replaces the EA-driven ChartExporter path).
    'aisita' => [
        'chart_url' => env('AISITA_CHART_URL', 'https://dev-backend.aisita.ai/api/gold-chart'),
        'token'     => env('AISITA_CHART_TOKEN'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'default_chat_id' => env('TELEGRAM_DEFAULT_CHAT_ID'),
        'drawdown_threshold' => env('TELEGRAM_DRAWDOWN_THRESHOLD', 2.0),
    ],

];
