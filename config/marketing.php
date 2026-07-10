<?php

$firstConfiguredEnv = static function (array $keys, $default = null) {
    foreach ($keys as $key) {
        $value = env($key);

        if ($value !== null && $value !== '') {
            return $value;
        }
    }

    return $default;
};

$publicBlogClientSiteId = $firstConfiguredEnv([
    'ARGUSLY_PUBLIC_BLOG_CLIENT_SITE_ID',
    'PL_PUBLIC_BLOG_CLIENT_SITE_ID',
    'PUBLISHLAYER_PUBLIC_BLOG_CLIENT_SITE_ID',
], '');

$publicBlogWorkspaceId = $firstConfiguredEnv([
    'ARGUSLY_PUBLIC_BLOG_WORKSPACE_ID',
    'PL_PUBLIC_BLOG_WORKSPACE_ID',
    'PUBLISHLAYER_PUBLIC_BLOG_WORKSPACE_ID',
], '');

return [
    'blog_source' => [
        // Explicit source for public marketing blog content.
        // Allowed modes: workspace, site.
        'mode' => $firstConfiguredEnv([
            'ARGUSLY_MARKETING_BLOG_SOURCE_MODE',
            'PL_MARKETING_BLOG_SOURCE_MODE',
            'PUBLISHLAYER_MARKETING_BLOG_SOURCE_MODE',
        ], $publicBlogClientSiteId !== '' ? 'site' : 'workspace'),
        'id' => $firstConfiguredEnv([
            'ARGUSLY_MARKETING_BLOG_SOURCE_ID',
            'PL_MARKETING_BLOG_SOURCE_ID',
            'PUBLISHLAYER_MARKETING_BLOG_SOURCE_ID',
        ], $publicBlogWorkspaceId !== '' ? $publicBlogWorkspaceId : $publicBlogClientSiteId),
    ],
];
