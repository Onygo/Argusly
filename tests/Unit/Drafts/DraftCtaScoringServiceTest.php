<?php

use App\Services\Drafts\DraftCtaScoringService;

it('scores a draft with the provided soft CTA higher than the same draft without it', function () {
    $service = app(DraftCtaScoringService::class);

    $baseArticle = <<<'TEXT'
Telecom teams can automate repetitive workflows faster when they start with one core process, map current blockers, and define a realistic first pilot. This article explains how to identify the right process, align stakeholders, and structure the first 30 days.
TEXT;

    $cta = <<<'TEXT'
Wil je verkennen hoe jij in 30 dagen een eerste kernproces kunt automatiseren? Plan dan een kort gesprek met je CTO of operations team en bepaal welke workflow zich het beste leent voor een eerste pilot. Gebruik dit artikel als checklist voor je eigen 30 dagen plan en zet vandaag de eerste stap richting slimmere telecom processen.
TEXT;

    $withoutCta = $service->evaluateContent('<p>' . $baseArticle . '</p>', [
        'title' => 'Telecom automation in 30 days',
        'primary_keyword' => 'telecom processen automatiseren',
        'secondary_keywords' => ['workflow pilot', '30 dagen plan'],
        'target_audience' => 'CTO and operations teams',
        'funnel_stage' => 'consideration',
    ]);

    $withCta = $service->evaluateContent('<p>' . $baseArticle . '</p><p>' . $cta . '</p>', [
        'title' => 'Telecom automation in 30 days',
        'primary_keyword' => 'telecom processen automatiseren',
        'secondary_keywords' => ['workflow pilot', '30 dagen plan'],
        'target_audience' => 'CTO and operations teams',
        'funnel_stage' => 'consideration',
    ]);

    expect($withCta['score'])->toBeGreaterThan($withoutCta['score'])
        ->and($withCta['score'])->toBeGreaterThanOrEqual(61)
        ->and($withCta['band_label'])->toBeIn([
            '61-80: clear, relevant, actionable CTA',
            '81-100: highly compelling, specific, well-matched CTA',
        ]);
});

it('keeps a consideration-stage soft CTA in a strong band without requiring a hard sales push', function () {
    $service = app(DraftCtaScoringService::class);

    $result = $service->evaluateContent(
        '<p>Teams often need a practical first workflow before rolling out broader automation.</p><p>Plan een kort gesprek met je operations team, kies de beste pilot workflow en gebruik dit artikel als checklist voor je eerste 30 dagen.</p>',
        [
            'title' => 'Automation pilot planning',
            'primary_keyword' => 'automation pilot workflow',
            'secondary_keywords' => ['operations team', '30 dagen'],
            'target_audience' => 'operations leaders',
            'funnel_stage' => 'consideration',
        ],
    );

    expect($result['score'])->toBeGreaterThanOrEqual(61)
        ->and($result['explanation'])->toContain('consideration-stage')
        ->and($result['explanation'])->toContain('without forcing a hard-sales ask');
});

it('returns the same CTA score for repeated evaluation of the same content', function () {
    $service = app(DraftCtaScoringService::class);

    $html = '<p>Use this guide to map your automation bottlenecks.</p><p>Plan a short workshop with your team, choose one pilot workflow, and use this checklist to launch your first 30-day automation sprint.</p>';
    $context = [
        'title' => 'Automation sprint guide',
        'primary_keyword' => 'automation sprint',
        'secondary_keywords' => ['pilot workflow', 'checklist'],
        'target_audience' => 'operations team',
        'funnel_stage' => 'consideration',
    ];

    $scores = collect(range(1, 5))
        ->map(fn (): int => app(DraftCtaScoringService::class)->evaluateContent($html, $context)['score'])
        ->all();

    expect(array_unique($scores))->toHaveCount(1);
});

it('keeps the explanation aligned with the numeric CTA band', function () {
    $service = app(DraftCtaScoringService::class);

    $result = $service->evaluateContent(
        '<p>This guide explains how to select the best workflow for automation.</p><p>Plan a short review with your operations lead, pick one pilot workflow, and use this checklist to shape your first 30-day rollout.</p>',
        [
            'title' => 'Workflow automation guide',
            'primary_keyword' => 'workflow automation',
            'secondary_keywords' => ['pilot workflow', '30-day rollout'],
            'target_audience' => 'operations leads',
            'funnel_stage' => 'consideration',
        ],
    );

    expect($result['score'])->toBeGreaterThanOrEqual(61);

    if ($result['score'] <= 80) {
        expect($result['band_label'])->toBe('61-80: clear, relevant, actionable CTA')
            ->and($result['explanation'])->toContain('clear, relevant, and actionable');
    } else {
        expect($result['band_label'])->toBe('81-100: highly compelling, specific, well-matched CTA')
            ->and($result['explanation'])->toContain('highly specific, compelling');
    }
});
