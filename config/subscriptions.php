<?php

return [
    'apple' => [
        'bundle_id' => env('APPLE_BUNDLE_ID'),

        'allowed_products' => [
            'carer.subscription.monthly',
        ],

        // legacy fallback
        'verify_receipt_url' => 'https://buy.itunes.apple.com/verifyReceipt',
        'verify_receipt_url_sandbox' => 'https://sandbox.itunes.apple.com/verifyReceipt',
        'shared_secret' => env('APPLE_SHARED_SECRET'),
    ],
];