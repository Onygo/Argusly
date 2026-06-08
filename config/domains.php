<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base Domain
    |--------------------------------------------------------------------------
    |
    | The root domain without subdomain prefix (e.g., argusly.local for
    | local development, argusly.com for production).
    |
    */

    'base' => env('DOMAIN_BASE', 'argusly.local'),

    /*
    |--------------------------------------------------------------------------
    | Subdomain Definitions
    |--------------------------------------------------------------------------
    |
    | Maps each subdomain to its configuration. The 'prefix' is prepended to
    | the base domain. An empty prefix means the apex/root domain.
    |
    */

    'subdomains' => [
        'marketing' => [
            'prefix' => '',
            'name' => 'marketing',
        ],
        'app' => [
            'prefix' => 'app',
            'name' => 'app',
        ],
        'admin' => [
            'prefix' => 'admin',
            'name' => 'admin',
        ],
        'api' => [
            'prefix' => 'api',
            'name' => 'api',
        ],
        'track' => [
            'prefix' => 'track',
            'name' => 'track',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Hosts
    |--------------------------------------------------------------------------
    |
    | Hosts that should NOT be handled by subdomain routing. These are
    | typically separate vhosts or demo environments.
    |
    */

    'excluded_hosts' => [
        'wordpress.argusly.local',
        'laravel.argusly.com',
    ],

];