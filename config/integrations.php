<?php

use App\Services\Integrations\LinkedIn\LinkedInProvider;

return [
    'providers' => [
        'linkedin' => [
            'name' => 'LinkedIn',
            'auth_type' => 'oauth2',
            'provider' => LinkedInProvider::class,
            'scopes' => ['openid', 'profile', 'email', 'w_member_social'],
            'future_scopes' => ['r_member_social', 'r_organization_social', 'w_organization_social'],
            'oauth' => [
                'authorization_url' => 'https://www.linkedin.com/oauth/v2/authorization',
                'token_url' => 'https://www.linkedin.com/oauth/v2/accessToken',
                'userinfo_url' => 'https://api.linkedin.com/v2/userinfo',
                'ugc_posts_url' => 'https://api.linkedin.com/v2/ugcPosts',
                'client_id' => env('LINKEDIN_CLIENT_ID'),
                'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
                'redirect_uri' => env('LINKEDIN_REDIRECT_URI'),
                'redirect_route' => 'settings.integrations.linkedin.callback',
                'enabled' => (bool) env('LINKEDIN_CLIENT_ID') && (bool) env('LINKEDIN_CLIENT_SECRET'),
            ],
            'supports' => [
                'personal_profiles' => true,
                'organization_pages' => false,
            ],
        ],
        'google' => [
            'name' => 'Google',
            'auth_type' => 'oauth2',
            'scopes' => ['openid', 'email', 'profile'],
        ],
        'wordpress' => [
            'name' => 'WordPress',
            'auth_type' => 'oauth2',
            'scopes' => ['read', 'write'],
        ],
        'laravel' => [
            'name' => 'Laravel',
            'auth_type' => 'api_key',
            'scopes' => ['read', 'write', 'deploy'],
        ],
        'meta' => [
            'name' => 'Meta',
            'auth_type' => 'oauth2',
            'scopes' => ['pages_read_engagement', 'pages_manage_posts'],
        ],
        'x' => [
            'name' => 'X',
            'auth_type' => 'oauth2',
            'scopes' => ['tweet.read', 'tweet.write', 'users.read', 'offline.access'],
        ],
        'youtube' => [
            'name' => 'YouTube',
            'auth_type' => 'oauth2',
            'scopes' => ['https://www.googleapis.com/auth/youtube.readonly'],
        ],
    ],

    'permission_levels' => [
        'use',
        'manage',
    ],
];
