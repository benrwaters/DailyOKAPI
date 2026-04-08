<?php

return [
    'reminder_window_minutes' => env('PUSH_REMINDER_WINDOW_MINUTES', 10),

    'apns' => [
        'use_sandbox' => (bool) env('APNS_USE_SANDBOX', true),
        'bundle_id' => env('APPLE_BUNDLE_ID'),
        'team_id' => env('APNS_TEAM_ID'),
        'key_id' => env('APNS_KEY_ID'),
        'private_key' => env('APNS_PRIVATE_KEY'),
        'private_key_path' => env('APNS_PRIVATE_KEY_PATH'),
    ],
];
