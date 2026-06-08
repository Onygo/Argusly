<?php

use App\Services\WordPress\Exceptions\MalformedResponseException;
use App\Services\WordPress\Exceptions\UnauthorizedException;
use App\Services\WordPress\WordPressConnector;
use Illuminate\Support\Facades\Http;

it('maps create responses into a normalized wordpress post', function () {
    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts' => Http::response([
            'wp_post_id' => '42',
            'url' => 'https://wp.example/?p=42',
            'status' => 'publish',
            'modified_gmt' => '2026-03-16 12:00:00',
        ], 201),
    ]);

    $post = app(WordPressConnector::class)
        ->forSite('https://wp.example', 'token')
        ->createPost(['title' => 'Connector create']);

    expect($post->id)->toBe('42')
        ->and($post->publishedUrl)->toBe('https://wp.example/?p=42')
        ->and($post->status)->toBe('publish')
        ->and($post->httpStatus)->toBe(201)
        ->and($post->modifiedTs)->toBeGreaterThan(0);
});

it('maps update responses into a normalized wordpress post', function () {
    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts/42' => Http::response([
            'data' => [
                'id' => '42',
                'url' => 'https://wp.example/?p=42',
                'status' => 'draft',
            ],
        ], 200),
    ]);

    $post = app(WordPressConnector::class)
        ->forSite('https://wp.example', 'token')
        ->updatePost('42', ['title' => 'Connector update']);

    expect($post->id)->toBe('42')
        ->and($post->publishedUrl)->toBe('https://wp.example/?p=42')
        ->and($post->status)->toBe('draft')
        ->and($post->httpStatus)->toBe(200);
});

it('treats 404 post lookups as a missing remote post', function () {
    Http::fake([
        'https://wp.example/*' => Http::response([
            'message' => 'No route or post found',
        ], 404),
    ]);

    $result = app(WordPressConnector::class)
        ->forSite('https://wp.example', 'token')
        ->postExists('999');

    expect($result->exists)->toBeFalse()
        ->and($result->isMissing())->toBeTrue()
        ->and($result->httpStatus)->toBe(404)
        ->and($result->post)->toBeNull();
});

it('throws an unauthorized exception for auth failures', function () {
    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts' => Http::response([
            'message' => 'Invalid token',
        ], 401),
    ]);

    expect(fn () => app(WordPressConnector::class)
        ->forSite('https://wp.example', 'token')
        ->createPost(['title' => 'Auth failure']))
        ->toThrow(UnauthorizedException::class, 'Invalid token');
});

it('throws a malformed response exception when the remote success payload has no post id', function () {
    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts' => Http::response([
            'ok' => true,
            'url' => 'https://wp.example/?p=42',
        ], 200),
    ]);

    expect(fn () => app(WordPressConnector::class)
        ->forSite('https://wp.example', 'token')
        ->createPost(['title' => 'Malformed response']))
        ->toThrow(MalformedResponseException::class, 'post identifier');
});

it('finds a wordpress post by argusly metadata through lookup responses', function () {
    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts/lookup*' => Http::response([
            'items' => [[
                'post_id' => '77',
                'link' => 'https://wp.example/?p=77',
                'status' => 'publish',
            ]],
        ], 200),
    ]);

    $post = app(WordPressConnector::class)
        ->forSite('https://wp.example', 'token')
        ->findPostByMeta([
            'meta_key' => 'argusly_draft_id',
            'meta_value' => 'draft-123',
        ]);

    expect($post)->not->toBeNull()
        ->and($post?->id)->toBe('77')
        ->and($post?->publishedUrl)->toBe('https://wp.example/?p=77');
});

it('handles exists false response from connector for post lookup', function () {
    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts/999' => Http::response([
            'exists' => false,
            'post_id' => null,
            'wp_post_id' => null,
            'error' => 'Post not found.',
        ], 404),
    ]);

    $result = app(WordPressConnector::class)
        ->forSite('https://wp.example', 'token')
        ->postExists('999');

    expect($result->exists)->toBeFalse()
        ->and($result->isMissing())->toBeTrue()
        ->and($result->httpStatus)->toBe(404)
        ->and($result->post)->toBeNull();
});

it('handles exists false response from connector lookup endpoint', function () {
    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts/lookup*' => Http::response([
            'exists' => false,
            'error' => 'Post not found.',
        ], 200),
    ]);

    $post = app(WordPressConnector::class)
        ->forSite('https://wp.example', 'token')
        ->findPostByMeta([
            'argusly_content_id' => 'content-123',
        ]);

    expect($post)->toBeNull();
});

it('handles normalized response shape from connector get endpoint', function () {
    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts/42' => Http::response([
            'exists' => true,
            'post_id' => '42',
            'wp_post_id' => '42',
            'status' => 'publish',
            'link' => 'https://wp.example/my-post/',
            'published_url' => 'https://wp.example/my-post/',
            'post_type' => 'post',
            'modified_gmt' => '2026-03-17 12:00:00',
            'external_key' => 'ext-key-123',
            'argusly_content_id' => 'content-456',
        ], 200),
    ]);

    $result = app(WordPressConnector::class)
        ->forSite('https://wp.example', 'token')
        ->postExists('42');

    expect($result->exists)->toBeTrue()
        ->and($result->post)->not->toBeNull()
        ->and($result->post->id)->toBe('42')
        ->and($result->post->publishedUrl)->toBe('https://wp.example/my-post/')
        ->and($result->post->status)->toBe('publish');
});

it('provides helpful error message for html responses', function () {
    Http::fake([
        'https://wp.example/*' => Http::response('<!DOCTYPE html><html><body>404 Not Found</body></html>', 200),
    ]);

    expect(fn () => app(WordPressConnector::class)
        ->forSite('https://wp.example', 'token')
        ->createPost(['title' => 'HTML response']))
        ->toThrow(MalformedResponseException::class, 'HTML instead of JSON');
});

it('provides helpful error message for empty responses', function () {
    Http::fake([
        'https://wp.example/*' => Http::response('', 200),
    ]);

    expect(fn () => app(WordPressConnector::class)
        ->forSite('https://wp.example', 'token')
        ->createPost(['title' => 'Empty response']))
        ->toThrow(MalformedResponseException::class, 'empty');
});
