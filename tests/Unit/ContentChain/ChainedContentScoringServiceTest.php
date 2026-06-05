<?php

use App\Services\ContentChain\ChainedContentScoringService;

it('scores chained content sources with an explainable breakdown', function () {
    config()->set('content_chain.suggestions.scoring.weights', [
        'quality_score' => 0.25,
        'page_views' => 0.20,
        'engagement_rate' => 0.20,
        'recency' => 0.10,
        'chain_gap' => 0.10,
        'manual_priority' => 0.10,
        'topical_gap' => 0.05,
    ]);
    config()->set('content_chain.suggestions.scoring.page_views_ceiling', 1000);
    config()->set('content_chain.suggestions.scoring.recency_window_days', 120);

    $result = app(ChainedContentScoringService::class)->scoreSource([
        'quality_score' => 82,
        'page_views' => 500,
        'engagement_rate' => 44,
        'recency_days' => 12,
        'chain_gap_score' => 75,
        'manual_priority' => 'high',
        'topical_gap_score' => 68,
    ]);

    expect($result['score'])->toBeGreaterThan(60.0)
        ->and($result['breakdown'])->toMatchArray([
            'quality_score' => 82.0,
            'page_views' => 50.0,
            'engagement_rate' => 44.0,
            'chain_gap' => 75.0,
            'manual_priority' => 82.0,
            'topical_gap' => 68.0,
        ])
        ->and($result['breakdown']['recency'])->toBeGreaterThan(80.0);
});

it('maps manual priority to stable editorial boost values', function () {
    $service = app(ChainedContentScoringService::class);

    expect($service->manualPriorityScore('critical'))->toBe(100.0)
        ->and($service->manualPriorityScore('high'))->toBe(82.0)
        ->and($service->manualPriorityScore('medium'))->toBe(55.0)
        ->and($service->manualPriorityScore('low'))->toBe(28.0);
});
