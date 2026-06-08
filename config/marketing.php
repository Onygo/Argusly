<?php

return [
    'blog_source' => [
        // Explicit source for public marketing blog content.
        // Allowed modes: workspace, site.
        'mode' => env(
            'ARGUSLY_MARKETING_BLOG_SOURCE_MODE',
            env('ARGUSLY_PUBLIC_BLOG_CLIENT_SITE_ID') ? 'site' : 'workspace'
        ),
        'id' => env(
            'ARGUSLY_MARKETING_BLOG_SOURCE_ID',
            env('ARGUSLY_PUBLIC_BLOG_WORKSPACE_ID', env('ARGUSLY_PUBLIC_BLOG_CLIENT_SITE_ID'))
        ),
    ],
];
