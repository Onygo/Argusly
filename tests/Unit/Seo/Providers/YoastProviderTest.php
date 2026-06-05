<?php

use App\Services\Seo\Providers\YoastProvider;

it('declares yoast seo capabilities and syncable field keys', function () {
    $provider = new YoastProvider();

    expect($provider->key())->toBe('yoast')
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
        ]);
});

it('maps publishlayer seo fields to yoast wordpress meta keys', function () {
    $provider = new YoastProvider();

    $mapped = $provider->mapToWordPressMeta([
        'seo_title' => '  Yoast title  ',
        'seo_meta_description' => 'Yoast description',
        'primary_keyword' => 'yoast focus keyword',
        'seo_canonical' => 'https://example.com/yoast',
        'seo_og_title' => 'Yoast OG title',
        'seo_og_description' => 'Yoast OG description',
        'seo_og_image' => 'https://cdn.example.com/yoast-og.png',
    ]);

    expect($mapped)->toBe([
        '_yoast_wpseo_title' => 'Yoast title',
        '_yoast_wpseo_metadesc' => 'Yoast description',
        '_yoast_wpseo_focuskw' => 'yoast focus keyword',
        '_yoast_wpseo_canonical' => 'https://example.com/yoast',
        '_yoast_wpseo_opengraph-title' => 'Yoast OG title',
        '_yoast_wpseo_opengraph-description' => 'Yoast OG description',
        '_yoast_wpseo_opengraph-image' => 'https://cdn.example.com/yoast-og.png',
    ]);
});

it('omits empty yoast meta values to avoid destructive seo updates', function () {
    $provider = new YoastProvider();

    $mapped = $provider->mapToWordPressMeta([
        'seo_title' => 'Yoast title',
        'seo_meta_description' => ' ',
        'primary_keyword' => '',
        'seo_canonical' => '',
    ]);

    expect($mapped)->toBe([
        '_yoast_wpseo_title' => 'Yoast title',
    ]);
});
