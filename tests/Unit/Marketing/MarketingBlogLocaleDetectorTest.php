<?php

use App\Models\Content;
use App\Models\ContentVersion;
use App\Services\Marketing\MarketingBlogLocaleDetector;
use App\Services\Marketing\MarketingBlogRedirectService;
use Illuminate\Support\Str;

it('detects dutch content on an english route as misplaced en', function () {
    $content = new Content([
        'id' => (string) Str::uuid(),
        'title' => 'Laravel SEO architectuur: zo ontwerp je een schaalbaar contentplatform',
        'language' => 'en',
        'publish_url_key' => 'argusly-laravel-seo-architectuur',
        'published_url' => url('/en/blog/argusly-laravel-seo-architectuur'),
        'seo_canonical' => url('/en/blog/argusly-laravel-seo-architectuur'),
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'is_source_locale' => true,
    ]);
    $content->exists = true;
    $content->syncOriginal();
    $content->setRelation('currentVersion', new ContentVersion([
        'meta' => ['excerpt' => 'Nederlandse uitleg over Laravel SEO architectuur.'],
        'body' => '<p>Dit artikel legt uit hoe je een schaalbaar contentplatform ontwerpt.</p>',
    ]));

    $detector = new MarketingBlogLocaleDetector(new MarketingBlogRedirectService());
    $result = $detector->detect($content);

    expect($result['is_candidate_misplaced_en'])->toBeTrue()
        ->and($result['should_normalize_to_nl'])->toBeTrue()
        ->and($result['text_locale'])->toBe('nl')
        ->and($result['route_locale'])->toBe('en');
});

it('marks translation-linked low-confidence mismatches for manual review', function () {
    $content = new Content([
        'id' => (string) Str::uuid(),
        'title' => 'API platform',
        'language' => 'en',
        'publish_url_key' => 'api-platform',
        'published_url' => url('/en/blog/api-platform'),
        'seo_canonical' => url('/en/blog/api-platform'),
        'translation_source_content_id' => (string) Str::uuid(),
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'is_source_locale' => false,
    ]);
    $content->exists = true;
    $content->syncOriginal();
    $content->setRelation('currentVersion', new ContentVersion([
        'meta' => ['excerpt' => 'de met en'],
        'body' => '<p>de en met</p>',
    ]));

    $detector = new MarketingBlogLocaleDetector(new MarketingBlogRedirectService());
    $result = $detector->detect($content);

    expect($result['needs_review'])->toBeTrue()
        ->and($result['reason'])->toContain('manual review');
});
