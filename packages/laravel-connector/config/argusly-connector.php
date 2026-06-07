<?php

declare(strict_types=1);

return [
    'api' => [
        'base_url' => env('ARGUSLY_CONNECTOR_API_URL', 'https://api.argusly.com'),
        'token' => env('ARGUSLY_CONNECTOR_TOKEN'),
        'send_api_key_alias' => (bool) env('ARGUSLY_CONNECTOR_SEND_API_KEY_ALIAS', false),
        'timeout' => (int) env('ARGUSLY_CONNECTOR_TIMEOUT', 15),
    ],

    'site' => [
        'id' => env('ARGUSLY_CONNECTOR_SITE_ID'),
        'name' => env('ARGUSLY_CONNECTOR_SITE_NAME', env('APP_NAME')),
        'url' => env('ARGUSLY_CONNECTOR_SITE_URL', env('APP_URL')),
    ],

    'destination' => [
        'id' => env('ARGUSLY_CONNECTOR_DESTINATION_ID'),
    ],

    'webhooks' => [
        'enabled' => (bool) env('ARGUSLY_CONNECTOR_WEBHOOKS_ENABLED', true),
        'secret' => env('ARGUSLY_CONNECTOR_WEBHOOK_SECRET'),
    ],
];
