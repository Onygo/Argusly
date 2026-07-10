<?php

beforeEach(function (): void {
    $this->originalMarketingBlogSourceEnv = [];

    foreach (marketingBlogSourceEnvKeys() as $key) {
        $this->originalMarketingBlogSourceEnv[$key] = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: null;
        setMarketingBlogSourceEnvVar($key, null);
    }
});

afterEach(function (): void {
    foreach ($this->originalMarketingBlogSourceEnv ?? [] as $key => $value) {
        setMarketingBlogSourceEnvVar($key, $value);
    }
});

it('prefers canonical argusly marketing blog source env keys', function () {
    setMarketingBlogSourceEnvVar('ARGUSLY_MARKETING_BLOG_SOURCE_MODE', 'workspace');
    setMarketingBlogSourceEnvVar('ARGUSLY_MARKETING_BLOG_SOURCE_ID', 'argusly-workspace');
    setMarketingBlogSourceEnvVar('PL_MARKETING_BLOG_SOURCE_MODE', 'site');
    setMarketingBlogSourceEnvVar('PL_MARKETING_BLOG_SOURCE_ID', 'legacy-site');

    $config = require base_path('config/marketing.php');

    expect(data_get($config, 'blog_source.mode'))->toBe('workspace')
        ->and(data_get($config, 'blog_source.id'))->toBe('argusly-workspace');
});

it('falls back to legacy pl marketing blog source env keys', function () {
    setMarketingBlogSourceEnvVar('PL_MARKETING_BLOG_SOURCE_MODE', 'site');
    setMarketingBlogSourceEnvVar('PL_MARKETING_BLOG_SOURCE_ID', 'legacy-site');

    $config = require base_path('config/marketing.php');

    expect(data_get($config, 'blog_source.mode'))->toBe('site')
        ->and(data_get($config, 'blog_source.id'))->toBe('legacy-site');
});

it('derives a site source from legacy public blog site id when explicit source keys are missing', function () {
    setMarketingBlogSourceEnvVar('PUBLISHLAYER_PUBLIC_BLOG_CLIENT_SITE_ID', 'legacy-public-site');

    $config = require base_path('config/marketing.php');

    expect(data_get($config, 'blog_source.mode'))->toBe('site')
        ->and(data_get($config, 'blog_source.id'))->toBe('legacy-public-site');
});

function marketingBlogSourceEnvKeys(): array
{
    return [
        'ARGUSLY_MARKETING_BLOG_SOURCE_MODE',
        'ARGUSLY_MARKETING_BLOG_SOURCE_ID',
        'PL_MARKETING_BLOG_SOURCE_MODE',
        'PL_MARKETING_BLOG_SOURCE_ID',
        'PUBLISHLAYER_MARKETING_BLOG_SOURCE_MODE',
        'PUBLISHLAYER_MARKETING_BLOG_SOURCE_ID',
        'ARGUSLY_PUBLIC_BLOG_CLIENT_SITE_ID',
        'ARGUSLY_PUBLIC_BLOG_WORKSPACE_ID',
        'PL_PUBLIC_BLOG_CLIENT_SITE_ID',
        'PL_PUBLIC_BLOG_WORKSPACE_ID',
        'PUBLISHLAYER_PUBLIC_BLOG_CLIENT_SITE_ID',
        'PUBLISHLAYER_PUBLIC_BLOG_WORKSPACE_ID',
    ];
}

function setMarketingBlogSourceEnvVar(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);

        return;
    }

    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
