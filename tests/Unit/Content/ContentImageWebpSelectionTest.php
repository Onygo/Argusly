<?php

use App\Models\ClientSite;
use App\Models\ContentImage;
use Illuminate\Support\Facades\Storage;

it('prefers webp ui urls when available', function () {
    Storage::fake('public');

    $image = new ContentImage([
        'thumbnail_path' => 'content-images/x-thumb.jpg',
        'thumbnail_webp_path' => 'content-images/x-thumb.webp',
        'medium_path' => 'content-images/x-medium.jpg',
        'medium_webp_path' => 'content-images/x-medium.webp',
        'original_path' => 'content-images/x-original.jpg',
    ]);

    expect($image->thumbnail_ui_url)->toContain('x-thumb.webp')
        ->and($image->medium_ui_url)->toContain('x-medium.webp')
        ->and($image->original_ui_url)->toContain('x-original.jpg');
});

it('falls back to jpg png ui urls when webp is missing', function () {
    Storage::fake('public');

    $image = new ContentImage([
        'thumbnail_path' => 'content-images/y-thumb.jpg',
        'medium_path' => 'content-images/y-medium.jpg',
        'original_path' => 'content-images/y-original.jpg',
    ]);

    expect($image->thumbnail_ui_url)->toContain('y-thumb.jpg')
        ->and($image->medium_ui_url)->toContain('y-medium.jpg');
});

it('selects wordpress-safe path by default and allows webp when site supports it', function () {
    $image = new ContentImage([
        'medium_path' => 'content-images/wp-medium.jpg',
        'medium_webp_path' => 'content-images/wp-medium.webp',
        'original_path' => 'content-images/wp-original.jpg',
    ]);

    $siteWithoutWebp = new ClientSite([
        'capabilities' => [],
    ]);
    $siteWithWebp = new ClientSite([
        'capabilities' => ['image_formats' => ['webp' => true]],
    ]);

    expect($image->getPathForWordPressUpload($siteWithoutWebp))->toBe('content-images/wp-medium.jpg')
        ->and($image->getWordPressUploadMimeType($siteWithoutWebp))->toBe('image/jpeg')
        ->and($image->getPathForWordPressUpload($siteWithWebp))->toBe('content-images/wp-medium.webp')
        ->and($image->getWordPressUploadMimeType($siteWithWebp))->toBe('image/webp');
});

it('falls back safely when webp is allowed but missing', function () {
    $image = new ContentImage([
        'medium_path' => 'content-images/wp-medium.png',
        'original_path' => 'content-images/wp-original.png',
    ]);

    $siteWithWebp = new ClientSite([
        'capabilities' => ['supports_webp' => true],
    ]);

    expect($image->getPathForWordPressUpload($siteWithWebp))->toBe('content-images/wp-medium.png')
        ->and($image->getWordPressUploadMimeType($siteWithWebp))->toBe('image/png');
});

it('falls back to image_url when storage path is missing for ui and wordpress url', function () {
    Storage::fake('public');

    $image = new ContentImage([
        'image_path' => 'content-images/missing-original.png',
        'image_url' => 'https://cdn.example.test/restored.png',
    ]);

    expect($image->medium_ui_url)->toBe('https://cdn.example.test/restored.png')
        ->and($image->original_ui_url)->toBe('https://cdn.example.test/restored.png')
        ->and($image->getPathForWordPressUpload(new ClientSite(['capabilities' => []])))->toBe('content-images/missing-original.png')
        ->and($image->getWordPressUploadUrl(new ClientSite(['capabilities' => []])))->toBe('https://cdn.example.test/restored.png');
});

it('builds an absolute wordpress upload url from connector public url when storage url is relative', function () {
    Storage::fake('public');
    Storage::disk('public')->put('content-images/wp-medium.jpg', 'image');
    config()->set('argusly.webhooks.connector_public_url', 'https://connector.argusly.test');

    $image = new ContentImage([
        'medium_path' => 'content-images/wp-medium.jpg',
        'original_path' => 'content-images/wp-original.jpg',
    ]);

    expect($image->getWordPressUploadUrl(new ClientSite(['capabilities' => []])))
        ->toBe('https://connector.argusly.test/storage/content-images/wp-medium.jpg');
});

it('rewrites localhost wordpress upload url to connector public url', function () {
    config()->set('argusly.webhooks.connector_public_url', 'https://connector.argusly.test');

    $image = new ContentImage([
        'image_path' => 'content-images/missing.png',
        'image_url' => 'http://localhost/storage/content-images/missing.png',
    ]);

    expect($image->getWordPressUploadUrl(new ClientSite(['capabilities' => []])))
        ->toBe('https://connector.argusly.test/storage/content-images/missing.png');
});
