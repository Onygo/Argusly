<?php

use App\Models\Brief;
use App\Services\DraftComparison\DraftScoreExpectationResolver;

it('resolves CTA expectations by funnel stage', function () {
    $resolver = app(DraftScoreExpectationResolver::class);

    $awarenessProfile = $resolver->resolveContentProfile(new Brief([
        'content_type' => 'blog',
        'funnel_stage' => 'awareness',
        'target_audience' => 'Marketing managers',
    ]));

    $considerationProfile = $resolver->resolveContentProfile(new Brief([
        'content_type' => 'blog',
        'funnel_stage' => 'consideration',
        'target_audience' => 'Marketing managers',
    ]));

    $decisionProfile = $resolver->resolveContentProfile(new Brief([
        'content_type' => 'landing',
        'funnel_stage' => 'decision',
        'target_audience' => 'Marketing managers',
    ]));

    $awareness = $resolver->interpretMetric('cta_strength', 22, $awarenessProfile);
    $consideration = $resolver->interpretMetric('cta_strength', 50, $considerationProfile);
    $decision = $resolver->interpretMetric('cta_strength', 75, $decisionProfile);

    expect((string) $awareness['expected_range_label'])->toContain('awareness')
        ->and((string) $awareness['status_label'])->toBe('Correct for funnel stage')
        ->and((string) $consideration['expected_range_label'])->toContain('consideration')
        ->and((string) $consideration['status_label'])->toBe('Good for audience')
        ->and((string) $decision['expected_range_label'])->toContain('decision')
        ->and((string) $decision['status_label'])->toBe('Ideal for conversion stage');
});

it('adjusts readability expectation for technical versus broad audiences', function () {
    $resolver = app(DraftScoreExpectationResolver::class);

    $technicalProfile = $resolver->resolveContentProfile(new Brief([
        'content_type' => 'blog',
        'target_audience' => 'CTO and developers',
        'funnel_stage' => 'consideration',
    ]));

    $broadProfile = $resolver->resolveContentProfile(new Brief([
        'content_type' => 'blog',
        'target_audience' => 'Marketing and business teams',
        'funnel_stage' => 'consideration',
    ]));

    $technical = $resolver->interpretMetric('readability_score', 40, $technicalProfile);
    $broad = $resolver->interpretMetric('readability_score', 40, $broadProfile);

    expect((string) $technical['expected_range_label'])->toContain('technical audience')
        ->and((string) $technical['status_label'])->toBe('Good for technical audience')
        ->and((string) $broad['expected_range_label'])->toContain('broad audience')
        ->and((string) $broad['status_level'])->toBe('needs_improvement');
});

it('returns contextual statuses for below, within, and above expected ranges', function () {
    $resolver = app(DraftScoreExpectationResolver::class);

    $profile = $resolver->resolveContentProfile(new Brief([
        'content_type' => 'blog',
        'funnel_stage' => 'awareness',
        'target_audience' => 'General audience',
    ]));

    $below = $resolver->interpretMetric('cta_strength', 3, $profile);
    $within = $resolver->interpretMetric('cta_strength', 20, $profile);
    $above = $resolver->interpretMetric('cta_strength', 34, $profile);

    expect((string) $below['status_level'])->toBe('misaligned')
        ->and((string) $within['status_level'])->toBe('ideal_for_context')
        ->and((string) $above['status_level'])->toBe('acceptable')
        ->and((string) $above['status_label'])->toBe('Slightly too strong for this stage');
});

it('calculates a strategy fit score from contextual metric alignment', function () {
    $resolver = app(DraftScoreExpectationResolver::class);

    $profile = $resolver->resolveContentProfile(new Brief([
        'content_type' => 'blog',
        'funnel_stage' => 'awareness',
        'target_audience' => 'CTO and developers',
        'search_intent' => 'informational',
    ]));

    $aligned = $resolver->strategyFit([
        'cta_strength' => 18,
        'readability_score' => 42,
        'structure_quality' => 80,
    ], $profile);

    $misaligned = $resolver->strategyFit([
        'cta_strength' => 78,
        'readability_score' => 82,
        'structure_quality' => 40,
    ], $profile);

    expect((float) ($aligned['score'] ?? 0.0))->toBeGreaterThan(80.0)
        ->and((string) $aligned['status_level'])->not->toBe('needs_improvement')
        ->and((float) ($misaligned['score'] ?? 100.0))->toBeLessThan(50.0)
        ->and((string) $misaligned['status_level'])->toBe('needs_improvement');
});
