<?php

use App\Models\LlmTrackingQuery;
use App\Services\LlmTracking\LlmVisibilityScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calculates the ai visibility score breakdown from deterministic hits', function () {
    $calculator = app(LlmVisibilityScoreCalculator::class);

    $query = new LlmTrackingQuery([
        'name' => 'AI visibility',
        'query_text' => 'best AI content tools for SEO',
        'target_brand' => 'Argusly',
        'target_domain' => 'argusly.com',
        'brand_terms' => ['Argusly', 'Argusly AI'],
        'competitor_terms' => ['Frase', 'MarketMuse'],
    ]);

    $score = $calculator->calculate(
        $query,
        'Argusly is a strong option for AI SEO workflows, while Frase is another recommendation.',
        [
            [
                'term' => 'Argusly',
                'count' => 1,
                'bucket' => 'first',
                'context_snippets' => ['Argusly is a strong option for AI SEO workflows.'],
            ],
        ],
        [
            [
                'term' => 'Frase',
                'count' => 1,
                'bucket' => 'middle',
                'context_snippets' => ['Frase is another recommendation.'],
            ],
        ],
        ['brand' => ['bucket' => 'first']],
        [
            ['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website'],
        ],
        ['argusly.com'],
        0,
        'block_1',
        'Argusly is a strong option for AI SEO workflows.',
    );

    expect((float) $score['presence_score'])->toBe(1.0);
    expect((float) $score['position_score'])->toBe(1.0);
    expect((float) $score['citation_score'])->toBe(0.65);
    expect((string) $score['context_label'])->toBe('positive');
    expect((float) $score['competitive_score'])->toBe(0.5);
    expect((float) $score['owned_visibility_score'])->toBeGreaterThan(0.8);
    expect((float) $score['competitor_pressure_score'])->toBe(0.5);
    expect((float) $score['real_world_gap_score'])->toBe(0.45);
    expect((float) $score['ai_visibility_score'])->toBeGreaterThan(0.55);
    expect(collect($score['entity_presence'])->firstWhere('term', 'MarketMuse')['present'])->toBeFalse();
    expect((float) data_get($score, 'visibility_breakdown.subscores_100.owned_visibility'))->toBeGreaterThan(80.0);
});

it('returns a zero score when argusly is not present', function () {
    $calculator = app(LlmVisibilityScoreCalculator::class);

    $query = new LlmTrackingQuery([
        'name' => 'Brand gap',
        'query_text' => 'content optimization platforms',
        'brand_terms' => ['Argusly'],
        'competitor_terms' => ['Frase'],
    ]);

    $score = $calculator->calculate(
        $query,
        'Frase is often mentioned for this type of workflow.',
        [],
        [
            [
                'term' => 'Frase',
                'count' => 1,
                'bucket' => 'first',
                'context_snippets' => ['Frase is often mentioned for this type of workflow.'],
            ],
        ],
        [],
    );

    expect((float) $score['presence_score'])->toBe(0.0);
    expect((float) $score['position_score'])->toBe(0.0);
    expect((float) $score['sentiment_score'])->toBe(0.0);
    expect((float) $score['competitive_score'])->toBe(0.0);
    expect((float) $score['competitor_pressure_score'])->toBe(1.0);
    expect((float) $score['ai_visibility_score'])->toBeLessThan(0.15);
    expect((bool) data_get($score, 'visibility_breakdown.missing_visibility'))->toBeTrue();
});

it('penalizes negative context and missing owned citations', function () {
    $calculator = app(LlmVisibilityScoreCalculator::class);

    $query = new LlmTrackingQuery([
        'name' => 'Brand sentiment',
        'query_text' => 'is Argusly difficult to use',
        'target_brand' => 'Argusly',
        'target_domain' => 'argusly.com',
        'brand_terms' => ['Argusly'],
        'competitor_terms' => ['AcmeSEO'],
    ]);

    $score = $calculator->calculate(
        $query,
        'Argusly can feel difficult for new teams, while AcmeSEO is easier to start with.',
        [
            [
                'term' => 'Argusly',
                'count' => 1,
                'bucket' => 'middle',
                'context_snippets' => ['Argusly can feel difficult for new teams.'],
                'first_position' => 10,
            ],
        ],
        [
            [
                'term' => 'AcmeSEO',
                'count' => 1,
                'bucket' => 'last',
                'context_snippets' => ['AcmeSEO is easier to start with.'],
            ],
        ],
        ['brand' => ['bucket' => 'middle']],
        [
            ['url' => 'https://example-news.com/review', 'domain' => 'example-news.com', 'type' => 'news'],
        ],
        ['example-news.com'],
        10,
        'block_2',
        'Argusly can feel difficult for new teams.',
    );

    expect((string) $score['context_label'])->toBe('negative');
    expect((float) $score['context_score'])->toBe(0.2);
    expect((float) $score['citation_score'])->toBe(0.55);
    expect((float) $score['competitor_share_score'])->toBe(0.5);
    expect((float) $score['earned_visibility_score'])->toBeGreaterThan(0.5);
    expect((float) $score['ai_visibility_score'])->toBeLessThan(0.7);
});

it('rewards earned authority and source diversity more honestly than owned-only citations', function () {
    $calculator = app(LlmVisibilityScoreCalculator::class);

    $query = new LlmTrackingQuery([
        'target_brand' => 'Argusly',
        'target_domain' => 'argusly.com',
        'brand_terms' => ['Argusly'],
        'competitor_terms' => ['Semrush'],
    ]);

    $ownedOnly = $calculator->calculate(
        $query,
        'Argusly is mentioned with its own page https://argusly.com/ai-visibility.',
        [['term' => 'Argusly', 'count' => 1, 'bucket' => 'first', 'context_snippets' => ['Argusly is mentioned.']]],
        [],
        ['brand' => ['bucket' => 'first']],
        [['url' => 'https://argusly.com/ai-visibility', 'domain' => 'argusly.com', 'type' => 'website']],
        ['argusly.com'],
    );

    $earned = $calculator->calculate(
        $query,
        'Argusly is mentioned by editorial roundups from Search Engine Journal and G2.',
        [['term' => 'Argusly', 'count' => 1, 'bucket' => 'first', 'context_snippets' => ['Argusly is mentioned by editorial roundups.']]],
        [],
        ['brand' => ['bucket' => 'first']],
        [
            ['url' => 'https://www.searchenginejournal.com/ai-visibility-tools', 'domain' => 'www.searchenginejournal.com', 'type' => 'blog'],
            ['url' => 'https://www.g2.com/categories/ai-seo', 'domain' => 'www.g2.com', 'type' => 'website'],
        ],
        ['www.searchenginejournal.com', 'www.g2.com'],
        provider: 'anthropic',
        providerEvidence: ['providers_seen' => ['openai', 'anthropic'], 'providers_with_brand' => ['openai', 'anthropic']],
    );

    expect((float) $ownedOnly['owned_visibility_score'])->toBeGreaterThan((float) $ownedOnly['earned_visibility_score']);
    expect((float) $earned['earned_visibility_score'])->toBeGreaterThan((float) $ownedOnly['earned_visibility_score']);
    expect((float) $earned['citation_diversity_score'])->toBeGreaterThan((float) $ownedOnly['citation_diversity_score']);
    expect((float) $earned['real_world_gap_score'])->toBe(0.0);
});
