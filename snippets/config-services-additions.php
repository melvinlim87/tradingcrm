<?php

// Add these entries into config/services.php under the returned array,
// alongside the existing 'postmark', 'ses', 'resend', 'slack' blocks.

return [

    // ... existing entries ...

    'ea' => [
        'token' => env('EA_PUSH_TOKEN'),
    ],

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'anthropic/claude-3.5-sonnet'),
        'temperature' => env('OPENROUTER_TEMPERATURE', 0.3),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'default_chat_id' => env('TELEGRAM_DEFAULT_CHAT_ID'),
        'drawdown_threshold' => env('TELEGRAM_DRAWDOWN_THRESHOLD', 2.0),
    ],

];
