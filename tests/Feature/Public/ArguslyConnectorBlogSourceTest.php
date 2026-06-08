<?php

use App\Exceptions\PublicBlogSourceUnavailableException;
use App\Services\PublicBlog\ConnectorFirstBlogSource;
use App\Services\PublicBlog\ConnectorSynchronizedBlogSource;
use App\Services\PublicBlog\ArguslyConnectorBlogSource;
use Illuminate\Support\Facades\Http;

it('fetches public blog posts through the argusly connector configuration', function () {
    config()->set('argusly_connector.public_blog.use_connector', true);
    config()->set('argusly_connector.public_blog.connector_endpoint', '/v1/public/blog/posts');
    config()->set('argusly_connector.public_blog.max_posts', 123);
    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', 'ws-1');
    config()->set('argusly_connector.connections.default', [
        'base_url' => 'https://api.argusly.test',
        'api_key' => 'pl-test-key',
        'workspace_id' => 'ws-header',
    ]);
    config()->set('argusly_connector.http', [
        'timeout_seconds' => 8,
        'retries' => 1,
        'retry_sleep_ms' => 10,
    ]);

    Http::fake([
        'https://api.argusly.test/v1/public/blog/posts*' => Http::response([
            'data' => [[
                'id' => 'post-1',
                'slug' => 'connector-post',
                'title' => 'Connector Post',
                'content' => '<p>hello</p>',
                'content_format' => 'html',
                'published_at' => '2026-02-20T09:00:00+00:00',
            ]],
        ], 200),
    ]);

    $posts = app(ArguslyConnectorBlogSource::class)->fetchPublishedPosts();

    expect($posts)->toHaveCount(1)
        ->and($posts[0]['slug'])->toBe('connector-post');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.argusly.test/v1/public/blog/posts?limit=123&workspace_id=ws-1'
            && $request->hasHeader('Authorization', 'Bearer pl-test-key')
            && $request->hasHeader('X-Argusly-Workspace', 'ws-header');
    });
});

it('returns no connector posts and avoids outbound request when blog source is not configured', function () {
    config()->set('argusly_connector.public_blog.use_connector', true);
    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', '');

    Http::fake();

    $posts = app(ArguslyConnectorBlogSource::class)->fetchPublishedPosts();

    expect($posts)->toBe([]);
    Http::assertNothingSent();
});

it('falls back to local blog source when connector is unavailable', function () {
    config()->set('argusly_connector.public_blog.fallback_to_local', true);

    $connector = Mockery::mock(ArguslyConnectorBlogSource::class);
    $connector->shouldReceive('isEnabled')->once()->andReturn(true);
    $connector->shouldReceive('fetchPublishedPosts')
        ->once()
        ->andThrow(new PublicBlogSourceUnavailableException('connector down'));

    $local = Mockery::mock(ConnectorSynchronizedBlogSource::class);
    $local->shouldReceive('fetchPublishedPosts')
        ->once()
        ->andReturn([[
            'id' => 'local-1',
            'slug' => 'local-post',
            'title' => 'Local Post',
            'content' => '<p>local</p>',
        ]]);

    $source = new ConnectorFirstBlogSource($connector, $local);
    $posts = $source->fetchPublishedPosts();

    expect($posts)->toHaveCount(1)
        ->and($posts[0]['slug'])->toBe('local-post');
});

it('uses argusly_connector.api values when connection defaults are not set', function () {
    config()->set('argusly_connector.public_blog.use_connector', true);
    config()->set('argusly_connector.public_blog.connector_endpoint', '/v1/public/blog/posts');
    config()->set('argusly_connector.public_blog.max_posts', 12);
    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', 'ws-from-scope');
    config()->set('argusly_connector.connections.default', []);
    config()->set('argusly_connector.api.base_url', 'https://api-from-api-config.argusly.test');
    config()->set('argusly_connector.api.api_key', 'api-config-key');
    config()->set('argusly_connector.api.workspace_id', 'ws-from-api-config');

    Http::fake([
        'https://api-from-api-config.argusly.test/v1/public/blog/posts*' => Http::response([
            'data' => [[
                'id' => 'post-api',
                'slug' => 'post-from-api-config',
                'title' => 'Post from API Config',
                'content' => '<p>hello</p>',
                'content_format' => 'html',
                'published_at' => '2026-03-02T09:00:00+00:00',
            ]],
        ], 200),
    ]);

    $posts = app(ArguslyConnectorBlogSource::class)->fetchPublishedPosts();

    expect($posts)->toHaveCount(1)
        ->and($posts[0]['slug'])->toBe('post-from-api-config');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api-from-api-config.argusly.test/v1/public/blog/posts?limit=12&workspace_id=ws-from-scope'
            && $request->hasHeader('Authorization', 'Bearer api-config-key')
            && $request->hasHeader('X-Argusly-Workspace', 'ws-from-api-config');
    });
});

it('builds request options with verify false for local host in local env when flag is enabled', function () {
    config()->set('argusly_connector.http_insecure_local', true);
    config()->set('app.env', 'local');

    $source = app(ArguslyConnectorBlogSource::class);
    $method = new ReflectionMethod($source, 'requestOptionsFor');
    $method->setAccessible(true);

    $options = $method->invoke($source, 'https://argusly.local/v1/public/blog/posts');

    expect($options)->toBe(['verify' => false]);
});

it('throws when insecure local tls flag is enabled in production env', function () {
    config()->set('argusly_connector.http_insecure_local', true);
    config()->set('app.env', 'production');

    $source = app(ArguslyConnectorBlogSource::class);
    $method = new ReflectionMethod($source, 'requestOptionsFor');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($source, 'https://argusly.local/v1/public/blog/posts'))
        ->toThrow(\RuntimeException::class, 'ARGUSLY_HTTP_INSECURE_LOCAL cannot be enabled in production.');
});
