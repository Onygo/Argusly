<?php

use App\Services\Seo\Providers\ArguslyProvider;

it('declares argusly seo capabilities and syncable field keys', function () {
    $provider = new ArguslyProvider();

    expect($provider->key())->toBe('argusly')
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

it('maps argusly seo fields to argusly wordpress meta keys', function () {
    $provider = new ArguslyProvider();

    $mapped = $provider->mapToWordPressMeta([
        'seo_title' => '  Argusly title  ',
        'seo_meta_description' => 'Argusly description',
        'primary_keyword' => 'argusly focus keyword',
        'seo_canonical' => 'https://example.com/pl',
        'seo_og_title' => 'Argusly OG title',
        'seo_og_description' => 'Argusly OG description',
        'seo_og_image' => 'https://cdn.example.com/pl-og.png',
        'seo_twitter_title' => 'Argusly Twitter title',
        'seo_twitter_description' => 'Argusly Twitter description',
    ]);

    expect($mapped)->toBe([
        '_pl_seo_title' => 'Argusly title',
        '_pl_seo_meta_description' => 'Argusly description',
        '_pl_seo_focus_keyword' => 'argusly focus keyword',
        '_pl_seo_canonical' => 'https://example.com/pl',
        '_pl_seo_og_title' => 'Argusly OG title',
        '_pl_seo_og_description' => 'Argusly OG description',
        '_pl_seo_og_image' => 'https://cdn.example.com/pl-og.png',
        '_pl_seo_twitter_title' => 'Argusly Twitter title',
        '_pl_seo_twitter_description' => 'Argusly Twitter description',
    ]);
});

it('omits empty argusly meta values to avoid destructive seo updates', function () {
    $provider = new ArguslyProvider();

    $mapped = $provider->mapToWordPressMeta([
        'seo_title' => 'Argusly title',
        'seo_meta_description' => '   ',
        'primary_keyword' => '',
        'seo_canonical' => '',
    ]);

    expect($mapped)->toBe([
        '_pl_seo_title' => 'Argusly title',
    ]);
});
