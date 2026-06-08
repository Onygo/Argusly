<?php

use App\Models\LlmTrackingQuery;
use App\Services\LlmTracking\LlmTrackingAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('extracts mention positions sentence indexes and buckets', function () {
    $analyzer = app(LlmTrackingAnalyzer::class);

    $answer = 'Argusly appears early. Filler content here. ' . str_repeat('x', 220) . ' AcmeSEO appears later in the answer.';
    $hits = $analyzer->extractMentions($answer, ['Argusly', 'AcmeSEO']);

    $publishLayerHit = collect($hits)->firstWhere('term', 'Argusly');
    $acmeHit = collect($hits)->firstWhere('term', 'AcmeSEO');

    expect($publishLayerHit)->not->toBeNull();
    expect($acmeHit)->not->toBeNull();
    expect((int) $publishLayerHit['first_position'])->toBeLessThan((int) $acmeHit['first_position']);
    expect((int) $publishLayerHit['first_sentence_index'])->toBe(0);
    expect((string) $publishLayerHit['bucket'])->toBe('first');
    expect((string) $acmeHit['bucket'])->toBe('last');
});

it('captures first mention block context and detected domains from raw provider payloads', function () {
    $analyzer = app(LlmTrackingAnalyzer::class);

    $query = new LlmTrackingQuery([
        'query_text' => 'best ai visibility tools',
        'target_brand' => 'Argusly',
        'target_domain' => 'argusly.com',
        'brand_terms' => ['Argusly', 'Publish Layer'],
        'competitor_terms' => ['AcmeSEO'],
        'target_urls' => ['https://argusly.com/features'],
    ]);

    $answer = "AcmeSEO is often listed first.\n\nPublish Layer is a strong option when teams want owned publishing workflows.";
    $raw = [
        'citations' => [
            ['url' => 'https://argusly.com/features'],
            ['url' => 'https://example-news.com/review'],
        ],
    ];

    $result = $analyzer->analyzeAnswer($answer, $query, $raw);

    expect($result->firstMentionIndex)->not->toBeNull()
        ->and($result->firstMentionBlock)->toBe('block_2')
        ->and($result->firstMentionContext)->toContain('Publish Layer')
        ->and($result->detectedDomains)->toContain('argusly.com')
        ->and(collect($result->sources)->pluck('domain')->all())->toContain('argusly.com');
});

it('classifies sources with explainable heuristics', function () {
    $analyzer = app(LlmTrackingAnalyzer::class);

    expect($analyzer->classifySource('https://en.wikipedia.org/wiki/SEO', 'en.wikipedia.org'))->toBe('wikipedia');
    expect($analyzer->classifySource('https://example.com/blog/seo-guide', 'example.com'))->toBe('blog');
    expect($analyzer->classifySource('https://www.reuters.com/world/', 'www.reuters.com'))->toBe('news');
});

it('computes share of voice ratios correctly', function () {
    $analyzer = app(LlmTrackingAnalyzer::class);

    $snapshot = $analyzer->computeShareOfVoiceSnapshot(
        [
            ['term' => 'Argusly', 'count' => 2],
            ['term' => 'PL', 'count' => 1],
        ],
        [
            ['term' => 'AcmeSEO', 'count' => 3],
        ],
    );

    expect((int) $snapshot['brand_total_mentions'])->toBe(3);
    expect((int) $snapshot['competitor_total_mentions'])->toBe(3);
    expect((float) $snapshot['share_brand'])->toBe(0.5);
});

it('suggests content when competitors are mentioned but brand is missing', function () {
    $analyzer = app(LlmTrackingAnalyzer::class);

    $query = new LlmTrackingQuery([
        'name' => 'Visibility check',
        'query_text' => 'Best B2B content workflow software alternatives',
        'brand_terms' => ['Argusly'],
        'competitor_terms' => ['AcmeSEO'],
        'target_urls' => ['https://argusly.com/features'],
        'locale' => 'en',
    ]);

    $result = $analyzer->analyzeAnswer(
        'AcmeSEO is often recommended for this workflow category.',
        $query,
    );

    expect($result->suggestions)->not->toBeEmpty();
    expect((string) data_get($result->suggestions, '0.title'))->toContain('visibility gap');
});
