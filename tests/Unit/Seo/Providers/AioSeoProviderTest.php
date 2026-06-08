<?php

use App\Services\Seo\Providers\AioSeoProvider;

it('declares aioseo seo capabilities and syncable field keys', function () {
    $provider = new AioSeoProvider();

    expect($provider->key())->toBe('aioseo')
        ->and($provider->supportsMetaTitle())->toBeTrue()
        ->and($provider->supportsMetaDescription())->toBeTrue()
        ->and($provider->supportsCanonical())->toBeTrue()
        ->and($provider->supportsOgTags())->toBeTrue()
        ->and($provider->syncableFieldKeys())->toBe([
            'seo_title',
            'seo_meta_description',
            'primary_keyword',
            'seo_canonical',
            'seo_og_title',
            'seo_og_description',
            'seo_og_image',
            'seo_twitter_title',
            'seo_twitter_description',
        ]);
});

it('maps argusly seo fields to aioseo wordpress meta keys', function () {
    $provider = new AioSeoProvider();

    $mapped = $provider->mapToWordPressMeta([
        'seo_title' => '  AIOSEO title  ',
        'seo_meta_description' => 'AIOSEO description',
        'primary_keyword' => 'aioseo focus keyword',
        'seo_canonical' => 'https://example.com/aioseo',
        'seo_og_title' => 'AIOSEO OG title',
        'seo_og_description' => 'AIOSEO OG description',
        'seo_og_image' => 'https://cdn.example.com/aioseo-og.png',
        'seo_twitter_title' => 'AIOSEO Twitter title',
        'seo_twitter_description' => 'AIOSEO Twitter description',
    ]);

    expect($mapped)->toBe([
        '_aioseo_title' => 'AIOSEO title',
        '_aioseo_description' => 'AIOSEO description',
        '_aioseo_focus_keyphrase' => 'aioseo focus keyword',
        '_aioseo_canonical_url' => 'https://example.com/aioseo',
        '_aioseo_og_title' => 'AIOSEO OG title',
        '_aioseo_og_description' => 'AIOSEO OG description',
        '_aioseo_og_image' => 'https://cdn.example.com/aioseo-og.png',
        '_aioseo_twitter_title' => 'AIOSEO Twitter title',
        '_aioseo_twitter_description' => 'AIOSEO Twitter description',
    ]);
});

it('omits empty aioseo meta values to avoid destructive seo updates', function () {
    $provider = new AioSeoProvider();

    $mapped = $provider->mapToWordPressMeta([
        'seo_title' => 'AIOSEO title',
        'seo_meta_description' => '   ',
        'primary_keyword' => ' ',
        'seo_canonical' => '',
    ]);

    expect($mapped)->toBe([
        '_aioseo_title' => 'AIOSEO title',
    ]);
});
