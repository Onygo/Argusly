<?php

return [
    'marketing_domain' => env('ARGUSLY_MARKETING_DOMAIN'),
    'app_domain' => env('ARGUSLY_APP_DOMAIN'),
    'api_domain' => env('ARGUSLY_API_DOMAIN'),
    'track_domain' => env('ARGUSLY_TRACK_DOMAIN', env('ARGUSLY_BASE_DOMAIN') ? 'track.'.env('ARGUSLY_BASE_DOMAIN') : 'track.argusly.com'),
    'pilot_signup_recipient' => env('ARGUSLY_PILOT_SIGNUP_RECIPIENT', 'hello@argusly.com'),
    'contact_recipient' => env('ARGUSLY_CONTACT_RECIPIENT', env('ARGUSLY_PILOT_SIGNUP_RECIPIENT', 'hello@argusly.com')),
];
