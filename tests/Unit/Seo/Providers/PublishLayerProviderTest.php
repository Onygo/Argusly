<?php

use App\Services\Seo\Providers\PublishLayerProvider;

it('declares publishlayer seo capabilities and syncable field keys', function () {
    $provider = new PublishLayerProvider();

    expect($provider->key())->toBe('publishlayer')
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

it('maps publishlayer seo fields to publishlayer wordpress meta keys', function () {
    $provider = new PublishLayerProvider();

    $mapped = $provider->mapToWordPressMeta([
        'seo_title' => '  PublishLayer title  ',
        'seo_meta_description' => 'PublishLayer description',
        'primary_keyword' => 'publishlayer focus keyword',
        'seo_canonical' => 'https://example.com/pl',
        'seo_og_title' => 'PublishLayer OG title',
        'seo_og_description' => 'PublishLayer OG description',
        'seo_og_image' => 'https://cdn.example.com/pl-og.png',
        'seo_twitter_title' => 'PublishLayer Twitter title',
        'seo_twitter_description' => 'PublishLayer Twitter description',
    ]);

    expect($mapped)->toBe([
        '_pl_seo_title' => 'PublishLayer title',
        '_pl_seo_meta_description' => 'PublishLayer description',
        '_pl_seo_focus_keyword' => 'publishlayer focus keyword',
        '_pl_seo_canonical' => 'https://example.com/pl',
        '_pl_seo_og_title' => 'PublishLayer OG title',
        '_pl_seo_og_description' => 'PublishLayer OG description',
        '_pl_seo_og_image' => 'https://cdn.example.com/pl-og.png',
        '_pl_seo_twitter_title' => 'PublishLayer Twitter title',
        '_pl_seo_twitter_description' => 'PublishLayer Twitter description',
    ]);
});

it('omits empty publishlayer meta values to avoid destructive seo updates', function () {
    $provider = new PublishLayerProvider();

    $mapped = $provider->mapToWordPressMeta([
        'seo_title' => 'PublishLayer title',
        'seo_meta_description' => '   ',
        'primary_keyword' => '',
        'seo_canonical' => '',
    ]);

    expect($mapped)->toBe([
        '_pl_seo_title' => 'PublishLayer title',
    ]);
});
