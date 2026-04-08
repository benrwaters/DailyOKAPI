<?php

return [
    'base_url' => 'https://api.bulksms.com/v1',
    'username' => env('BULKSMS_USERNAME'),
    'password' => env('BULKSMS_PASSWORD'),
    'from' => env('BULKSMS_FROM'),
    'enabled' => (bool) env('BULKSMS_ENABLED', false),
];
