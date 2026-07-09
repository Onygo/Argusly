<?php

use App\Support\SeoTitle;

it('keeps seo titles within the Bing title length guidance', function () {
    $title = SeoTitle::normalize('AI Visibility & Agentic Marketing | From AI Search Insights to Execution | Argusly');

    expect(mb_strlen($title))->toBeLessThanOrEqual(SeoTitle::MAX_LENGTH)
        ->and($title)->toContain('Argusly');
});

it('omits the suffix when an article title already fits the length guidance', function () {
    $title = SeoTitle::withSuffix('How AI Agents Collaborate Across SEO, GEO, Content and Analytics', 'Argusly Blog');

    expect(mb_strlen($title))->toBeLessThanOrEqual(SeoTitle::MAX_LENGTH)
        ->and($title)->toBe('How AI Agents Collaborate Across SEO, GEO, Content and Analytics');
});
