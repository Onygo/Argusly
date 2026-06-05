<?php

use App\Exceptions\PublicBlogSourceUnavailableException;
use App\Services\PublicBlog\ConnectorFirstBlogSource;
use App\Services\PublicBlog\ConnectorSynchronizedBlogSource;
use App\Services\PublicBlog\PublishLayerConnectorBlogSource;
use Illuminate\Support\Facades\Http;

it('fetches public blog posts through the publishlayer connector configuration', function () {
    config()->set('publishlayer_connector.public_blog.use_connector', true);
    config()->set('publishlayer_connector.public_blog.connector_endpoint', '/v1/public/blog/posts');
    config()->set('publishlayer_connector.public_blog.max_posts', 123);
    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', 'ws-1');
    config()->set('publishlayer_connector.connections.default', [
        'base_url' => 'https://api.publishlayer.test',
        'api_key' => 'pl-test-key',
        'workspace_id' => 'ws-header',
    ]);
    config()->set('publishlayer_connector.http', [
        'timeout_seconds' => 8,
        'retries' => 1,
        'retry_sleep_ms' => 10,
    ]);

    Http::fake([
        'https://api.publishlayer.test/v1/public/blog/posts*' => Http::response([
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

    $posts = app(PublishLayerConnectorBlogSource::class)->fetchPublishedPosts();

    expect($posts)->toHaveCount(1)
        ->and($posts[0]['slug'])->toBe('connector-post');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.publishlayer.test/v1/public/blog/posts?limit=123&workspace_id=ws-1'
            && $request->hasHeader('Authorization', 'Bearer pl-test-key')
            && $request->hasHeader('X-PublishLayer-Workspace', 'ws-header');
    });
});

it('returns no connector posts and avoids outbound request when blog source is not configured', function () {
    config()->set('publishlayer_connector.public_blog.use_connector', true);
    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', '');

    Http::fake();

    $posts = app(PublishLayerConnectorBlogSource::class)->fetchPublishedPosts();

    expect($posts)->toBe([]);
    Http::assertNothingSent();
});

it('falls back to local blog source when connector is unavailable', function () {
    config()->set('publishlayer_connector.public_blog.fallback_to_local', true);

    $connector = Mockery::mock(PublishLayerConnectorBlogSource::class);
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

it('uses publishlayer_connector.api values when connection defaults are not set', function () {
    config()->set('publishlayer_connector.public_blog.use_connector', true);
    config()->set('publishlayer_connector.public_blog.connector_endpoint', '/v1/public/blog/posts');
    config()->set('publishlayer_connector.public_blog.max_posts', 12);
    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', 'ws-from-scope');
    config()->set('publishlayer_connector.connections.default', []);
    config()->set('publishlayer_connector.api.base_url', 'https://api-from-api-config.publishlayer.test');
    config()->set('publishlayer_connector.api.api_key', 'api-config-key');
    config()->set('publishlayer_connector.api.workspace_id', 'ws-from-api-config');

    Http::fake([
        'https://api-from-api-config.publishlayer.test/v1/public/blog/posts*' => Http::response([
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

    $posts = app(PublishLayerConnectorBlogSource::class)->fetchPublishedPosts();

    expect($posts)->toHaveCount(1)
        ->and($posts[0]['slug'])->toBe('post-from-api-config');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api-from-api-config.publishlayer.test/v1/public/blog/posts?limit=12&workspace_id=ws-from-scope'
            && $request->hasHeader('Authorization', 'Bearer api-config-key')
            && $request->hasHeader('X-PublishLayer-Workspace', 'ws-from-api-config');
    });
});

it('builds request options with verify false for local host in local env when flag is enabled', function () {
    config()->set('publishlayer_connector.http_insecure_local', true);
    config()->set('app.env', 'local');

    $source = app(PublishLayerConnectorBlogSource::class);
    $method = new ReflectionMethod($source, 'requestOptionsFor');
    $method->setAccessible(true);

    $options = $method->invoke($source, 'https://publishlayer.local/v1/public/blog/posts');

    expect($options)->toBe(['verify' => false]);
});

it('throws when insecure local tls flag is enabled in production env', function () {
    config()->set('publishlayer_connector.http_insecure_local', true);
    config()->set('app.env', 'production');

    $source = app(PublishLayerConnectorBlogSource::class);
    $method = new ReflectionMethod($source, 'requestOptionsFor');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($source, 'https://publishlayer.local/v1/public/blog/posts'))
        ->toThrow(\RuntimeException::class, 'PUBLISHLAYER_HTTP_INSECURE_LOCAL cannot be enabled in production.');
});
