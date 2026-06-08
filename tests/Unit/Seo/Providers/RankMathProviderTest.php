<?php

use App\Services\Seo\Providers\RankMathProvider;

it('declares rankmath seo capabilities and syncable field keys', function () {
    $provider = new RankMathProvider();

    expect($provider->key())->toBe('rankmath')
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

it('maps argusly seo fields to rankmath wordpress meta keys', function () {
    $provider = new RankMathProvider();

    $mapped = $provider->mapToWordPressMeta([
        'seo_title' => '  Rank title  ',
        'seo_meta_description' => 'Rank description',
        'primary_keyword' => 'rank focus keyword',
        'seo_canonical' => 'https://example.com/rank',
        'seo_og_title' => 'Rank OG title',
        'seo_og_description' => 'Rank OG description',
        'seo_og_image' => 'https://cdn.example.com/og.png',
        'seo_twitter_title' => 'Rank Twitter title',
        'seo_twitter_description' => 'Rank Twitter description',
    ]);

    expect($mapped)->toBe([
        'rank_math_title' => 'Rank title',
        'rank_math_description' => 'Rank description',
        'rank_math_focus_keyword' => 'rank focus keyword',
        'rank_math_canonical_url' => 'https://example.com/rank',
        'rank_math_facebook_title' => 'Rank OG title',
        'rank_math_facebook_description' => 'Rank OG description',
        'rank_math_facebook_image' => 'https://cdn.example.com/og.png',
        'rank_math_twitter_title' => 'Rank Twitter title',
        'rank_math_twitter_description' => 'Rank Twitter description',
    ]);
});

it('omits empty rankmath meta values to avoid destructive seo updates', function () {
    $provider = new RankMathProvider();

    $mapped = $provider->mapToWordPressMeta([
        'seo_title' => 'Rank title',
        'seo_meta_description' => '   ',
        'primary_keyword' => '',
        'seo_canonical' => '',
    ]);

    expect($mapped)->toBe([
        'rank_math_title' => 'Rank title',
    ]);
});
