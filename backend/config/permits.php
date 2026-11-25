<?php

return [
    'sign_mode' => env('SIGN_MODE', 'emulate'),
    'notify_time' => env('NOTIFY_TIME', '09:00'),
    'mailpit_url' => env('MAILPIT_URL'),
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'api' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
    ],
    'scan_max_kb' => 10240,
];
