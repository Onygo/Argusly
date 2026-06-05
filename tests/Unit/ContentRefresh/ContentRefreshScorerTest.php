<?php

use App\Agents\ContentRefresh\ContentRefreshScorer;
use App\Models\Content;
use Carbon\Carbon;

it('scores stale content higher than recent content when other signals are equal', function () {
    $scorer = app(ContentRefreshScorer::class);

    $stale = new Content([
        'title' => 'Stale article',
        'type' => 'article',
        'seo_title' => 'Stale article',
        'seo_meta_description' => 'Meta description',
        'seo_h1' => 'Stale article',
    ]);
    $recent = new Content([
        'title' => 'Recent article',
        'type' => 'article',
        'seo_title' => 'Recent article',
        'seo_meta_description' => 'Meta description',
        'seo_h1' => 'Recent article',
    ]);

    $staleScore = $scorer->score([
        'content' => $stale,
        'word_count' => 1000,
        'internal_link_count' => 3,
        'has_faq' => true,
        'body_years' => [],
        'outdated_locales' => [],
        'newer_chain_article_count' => 0,
        'latest_content_reference_at' => Carbon::now()->subDays(420),
    ]);
    $recentScore = $scorer->score([
        'content' => $recent,
        'word_count' => 1000,
        'internal_link_count' => 3,
        'has_faq' => true,
        'body_years' => [],
        'outdated_locales' => [],
        'newer_chain_article_count' => 0,
        'latest_content_reference_at' => Carbon::now()->subDays(15),
    ]);

    expect($staleScore['refresh_score'])->toBeGreaterThan($recentScore['refresh_score']);
});

it('increases the refresh score when seo structure is missing', function () {
    $scorer = app(ContentRefreshScorer::class);

    $complete = new Content([
        'title' => 'Structured article',
        'type' => 'article',
        'seo_title' => 'Structured article',
        'seo_meta_description' => 'Meta description',
        'seo_h1' => 'Structured article',
    ]);
    $missing = new Content([
        'title' => 'Unstructured article',
        'type' => 'article',
        'seo_title' => null,
        'seo_meta_description' => null,
        'seo_h1' => null,
    ]);

    $completeScore = $scorer->score([
        'content' => $complete,
        'word_count' => 1000,
        'internal_link_count' => 3,
        'has_faq' => true,
        'body_years' => [],
        'outdated_locales' => [],
        'newer_chain_article_count' => 0,
        'latest_content_reference_at' => Carbon::now()->subDays(30),
    ]);
    $missingScore = $scorer->score([
        'content' => $missing,
        'word_count' => 1000,
        'internal_link_count' => 3,
        'has_faq' => true,
        'body_years' => [],
        'outdated_locales' => [],
        'newer_chain_article_count' => 0,
        'latest_content_reference_at' => Carbon::now()->subDays(30),
    ]);

    expect($missingScore['refresh_score'])->toBeGreaterThan($completeScore['refresh_score'])
        ->and(collect($missingScore['signals'])->pluck('key')->all())->toContain('missing_seo_structure');
});

it('lets weak internal linking influence the refresh score', function () {
    $scorer = app(ContentRefreshScorer::class);

    $content = new Content([
        'title' => 'Linking article',
        'type' => 'article',
        'seo_title' => 'Linking article',
        'seo_meta_description' => 'Meta description',
        'seo_h1' => 'Linking article',
    ]);

    $lowLinks = $scorer->score([
        'content' => $content,
        'word_count' => 1000,
        'internal_link_count' => 0,
        'has_faq' => true,
        'body_years' => [],
        'outdated_locales' => [],
        'newer_chain_article_count' => 0,
        'latest_content_reference_at' => Carbon::now()->subDays(30),
    ]);
    $healthyLinks = $scorer->score([
        'content' => $content,
        'word_count' => 1000,
        'internal_link_count' => 4,
        'has_faq' => true,
        'body_years' => [],
        'outdated_locales' => [],
        'newer_chain_article_count' => 0,
        'latest_content_reference_at' => Carbon::now()->subDays(30),
    ]);

    expect($lowLinks['refresh_score'])->toBeGreaterThan($healthyLinks['refresh_score'])
        ->and(collect($lowLinks['signals'])->pluck('key')->all())->toContain('weak_internal_linking');
});

it('raises a refresh signal when duplicate or similar titles exist', function () {
    $scorer = app(ContentRefreshScorer::class);

    $content = new Content([
        'title' => 'From SEO to AI Visibility',
        'type' => 'article',
        'seo_title' => 'From SEO to AI Visibility',
        'seo_meta_description' => 'Meta description',
        'seo_h1' => 'From SEO to AI Visibility',
    ]);

    $scorecard = $scorer->score([
        'content' => $content,
        'word_count' => 1000,
        'internal_link_count' => 3,
        'has_faq' => true,
        'body_years' => [],
        'outdated_locales' => [],
        'newer_chain_article_count' => 0,
        'duplicate_title_risks' => [
            [
                'title' => 'From SEO to AI Visibility: A Practical Guide',
                'match_type' => 'similar_title',
                'similarity' => 91,
            ],
        ],
        'latest_content_reference_at' => Carbon::now()->subDays(30),
    ]);

    expect(collect($scorecard['signals'])->pluck('key')->all())->toContain('duplicate_title_risk')
        ->and($scorecard['refresh_score'])->toBeGreaterThan(0);
});
