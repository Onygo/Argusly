<?php

use App\Models\Draft;
use App\Models\DraftAnalysis;
use App\Services\Drafts\Intelligence\DraftRecommendationEngine;

it('creates a high-priority cta recommendation when the cta score is low and signals show no clear cta', function () {
    $analysis = new DraftAnalysis([
        'cta_score' => 32,
        'signals_payload' => [
            'cta' => [
                'cta_present' => false,
                'cta_near_end' => false,
                'weak_generic_cta' => true,
            ],
            'seo' => [],
            'readability' => [],
            'headings' => [],
        ],
        'normalized_payload' => [
            'sections' => [
                'seo' => ['score' => 72],
                'readability' => ['score' => 74],
                'cta' => ['score' => 32, 'explanation' => 'No clear CTA.', 'improvements' => ['Add a CTA.']],
                'structure' => ['score' => 76],
            ],
        ],
    ]);

    $recommendations = app(DraftRecommendationEngine::class)->generate(new Draft(), $analysis);

    expect($recommendations)->not->toBeEmpty()
        ->and($recommendations[0]['metric_key'])->toBe('cta')
        ->and($recommendations[0]['title'])->toContain('CTA')
        ->and(data_get($recommendations[0], 'context_payload.signal'))->toBe('cta_present');
});

it('removes the cta recommendation when the cta score is already strong and signals confirm a clear closing cta', function () {
    $analysis = new DraftAnalysis([
        'cta_score' => 72,
        'signals_payload' => [
            'cta' => [
                'cta_present' => true,
                'cta_near_end' => true,
                'weak_generic_cta' => false,
            ],
            'seo' => [
                'title_has_primary_keyword' => false,
                'intro_has_primary_keyword' => false,
                'meta_title_present' => true,
                'meta_description_present' => true,
                'keyword_stuffing_detected' => false,
                'related_terms_present' => 0,
                'related_term_total' => 1,
                'headings_with_primary_keyword' => 0,
                'internal_link_present' => false,
            ],
            'readability' => [],
            'headings' => [],
        ],
        'normalized_payload' => [
            'sections' => [
                'seo' => ['score' => 48],
                'readability' => ['score' => 78],
                'cta' => ['score' => 72, 'explanation' => 'CTA is strong.', 'improvements' => []],
                'structure' => ['score' => 79],
            ],
        ],
    ]);

    $recommendations = app(DraftRecommendationEngine::class)->generate(new Draft(), $analysis);

    expect(collect($recommendations)->pluck('metric_key')->all())->not->toContain('cta')
        ->and($recommendations[0]['metric_key'])->toBe('seo');
});

it('orders recommendations stably by impact confidence and effort', function () {
    $analysis = new DraftAnalysis([
        'signals_payload' => [
            'cta' => ['cta_present' => false],
            'seo' => [
                'title_has_primary_keyword' => false,
                'intro_has_primary_keyword' => false,
                'meta_title_present' => false,
                'meta_description_present' => false,
                'keyword_stuffing_detected' => false,
                'related_terms_present' => 0,
                'related_term_total' => 1,
                'headings_with_primary_keyword' => 0,
                'internal_link_present' => false,
            ],
            'readability' => [
                'dense_block_count' => 3,
                'average_sentence_words' => 28,
                'scanability' => false,
            ],
            'headings' => [
                'h1_present' => false,
                'generic_heading_count' => 2,
                'hierarchy_consistent' => false,
            ],
        ],
        'normalized_payload' => [
            'sections' => [
                'seo' => ['score' => 35],
                'readability' => ['score' => 42],
                'cta' => ['score' => 20],
                'structure' => ['score' => 30],
            ],
        ],
    ]);

    $recommendations = app(DraftRecommendationEngine::class)->generate(new Draft(), $analysis);

    expect(count($recommendations))->toBeGreaterThan(2)
        ->and($recommendations[0]['priority_score'])->toBeGreaterThanOrEqual($recommendations[1]['priority_score'])
        ->and($recommendations[1]['priority_score'])->toBeGreaterThanOrEqual($recommendations[2]['priority_score']);
});

it('creates llm visibility recommendations when the draft is hard for ai systems to extract', function () {
    $analysis = new DraftAnalysis([
        'signals_payload' => [
            'llm_visibility' => [
                'explicit_answer_presence' => false,
                'extractable_summary_block_present' => false,
                'entity_clarity_ratio' => 0.3,
                'step_based_section_present' => false,
                'comparison_pattern_present' => false,
            ],
        ],
        'normalized_payload' => [
            'sections' => [
                'llm_visibility' => [
                    'score' => 34,
                    'explanation' => 'The draft is hard for AI systems to extract cleanly.',
                    'improvements' => ['Add a concise summary block.'],
                ],
            ],
        ],
    ]);

    $recommendations = app(DraftRecommendationEngine::class)->generate(new Draft(), $analysis);

    $llmVisibilityTitles = collect($recommendations)
        ->where('metric_key', 'llm_visibility')
        ->pluck('title')
        ->implode(' | ');

    expect(collect($recommendations)->pluck('metric_key')->all())->toContain('llm_visibility')
        ->and($llmVisibilityTitles)->toContain('summary block');
});

it('creates a publish readiness recommendation when blocking issues remain', function () {
    $analysis = new DraftAnalysis([
        'normalized_payload' => [
            'sections' => [
                'publish_readiness' => [
                    'score' => 34,
                    'explanation' => 'The draft is not ready to publish.',
                    'improvements' => ['Add a CTA and resolve the weakest issue.'],
                    'status_label' => 'Not ready',
                    'blocking_issues' => ['Add a clear CTA to the conclusion before publishing.'],
                    'recommended_next_actions' => ['Add a clear CTA to the conclusion before publishing.'],
                ],
            ],
        ],
    ]);

    $recommendations = app(DraftRecommendationEngine::class)->generate(new Draft(), $analysis);

    expect(collect($recommendations)->pluck('metric_key')->all())->toContain('publish_readiness')
        ->and(collect($recommendations)->firstWhere('metric_key', 'publish_readiness')['title'])->toContain('blocking issues');
});
