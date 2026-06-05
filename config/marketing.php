<?php

return [
    'blog_source' => [
        // Explicit source for public marketing blog content.
        // Allowed modes: workspace, site.
        'mode' => env(
            'PL_MARKETING_BLOG_SOURCE_MODE',
            env('PUBLISHLAYER_PUBLIC_BLOG_CLIENT_SITE_ID') ? 'site' : 'workspace'
        ),
        'id' => env(
            'PL_MARKETING_BLOG_SOURCE_ID',
            env('PUBLISHLAYER_PUBLIC_BLOG_WORKSPACE_ID', env('PUBLISHLAYER_PUBLIC_BLOG_CLIENT_SITE_ID'))
        ),
    ],
];
