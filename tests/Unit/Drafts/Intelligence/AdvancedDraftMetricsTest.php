<?php

use App\Models\Brief;
use App\Models\Draft;
use App\Services\Drafts\Intelligence\DraftMetricScorer;
use App\Services\Drafts\Intelligence\DraftSignalExtractor;
use App\Services\Drafts\Intelligence\DraftStructureParser;
use App\Services\Drafts\Intelligence\PublishReadinessEvaluator;
use Illuminate\Support\Collection;

it('degrades brand voice scoring gracefully when brand config is minimal', function () {
    $result = advancedMetricScoreForHtml(
        '<h1>Telecom automation guide</h1><p>Telecom automation helps operations teams reduce repetitive work with one practical pilot.</p><p>Start with one workflow and define the owner before expanding.</p>',
        brief: [
            'primary_keyword' => 'telecom automation',
            'target_audience' => '',
            'tone_of_voice' => '',
            'funnel_stage' => 'consideration',
        ],
        snapshotOverrides: [
            'brand_voice' => [],
            'company_profile' => [],
        ],
    );

    expect(data_get($result, 'sections.brand_voice_fit.score'))->toBeGreaterThanOrEqual(60)
        ->and((string) data_get($result, 'sections.brand_voice_fit.explanation'))->toContain('available audience and tone signals');
});

it('keeps conversion fit distinct from the CTA score', function () {
    $result = advancedMetricScoreForHtml(
        '<h1>Automation ideas</h1><p>Automation can help many teams reduce repetitive work.</p><p>Book a demo today.</p>',
        brief: [
            'primary_keyword' => 'automation',
            'call_to_action' => 'Book a demo',
            'funnel_stage' => 'consideration',
            'target_audience' => 'operations teams',
        ],
    );

    expect(data_get($result, 'sections.cta.score'))->toBeGreaterThan(data_get($result, 'sections.conversion_fit.score'))
        ->and(abs((int) data_get($result, 'sections.cta.score') - (int) data_get($result, 'sections.conversion_fit.score')))->toBeGreaterThanOrEqual(5);
});

it('rewards concrete trust framing and penalizes vague hype', function () {
    $strong = advancedMetricScoreForHtml(
        '<h1>Telecom automation in 30 days</h1><p>In 30 days, one operations team can automate a first provisioning workflow and measure one pilot outcome.</p><p>For example, start with ticket routing, assign an owner, and review the time saved after two weeks.</p><p>Plan a short workshop with your operations team and document the first pilot result.</p>',
        brief: [
            'primary_keyword' => 'telecom automation',
            'call_to_action' => 'Plan a pilot workshop',
            'funnel_stage' => 'consideration',
        ],
    );

    $weak = advancedMetricScoreForHtml(
        '<h1>Automation wins</h1><p>This revolutionary approach always delivers seamless results for every team.</p><p>It is the ultimate way to transform everything with no friction.</p>',
        brief: [
            'primary_keyword' => 'automation',
            'funnel_stage' => 'awareness',
        ],
    );

    expect(data_get($strong, 'sections.trust_evidence.score'))->toBeGreaterThan(data_get($weak, 'sections.trust_evidence.score'));
});

it('changes publish readiness status based on blocking metric combinations', function () {
    $evaluator = app(PublishReadinessEvaluator::class);

    $notReady = $evaluator->evaluate(
        [
            'funnel_stage' => 'decision',
            'seo_title' => '',
            'seo_meta_description' => '',
        ],
        [
            'cta' => ['cta_present' => false],
            'readability' => ['dense_block_count' => 3],
            'headings' => ['h1_present' => false],
            'trust_evidence' => ['overclaim_count' => 3],
            'brand_voice_fit' => ['guidance_available' => true],
        ],
        [
            'seo' => ['score' => 38],
            'readability' => ['score' => 34],
            'cta' => ['score' => 20],
            'structure' => ['score' => 30],
            'llm_visibility' => ['score' => 42],
            'brand_voice_fit' => ['score' => 40],
            'conversion_fit' => ['score' => 28],
            'trust_evidence' => ['score' => 25],
            'entities' => ['score' => 60],
        ],
    );

    $ready = $evaluator->evaluate(
        [
            'funnel_stage' => 'consideration',
            'seo_title' => 'Telecom automation in 30 days',
            'seo_meta_description' => 'A practical telecom automation guide.',
        ],
        [
            'cta' => ['cta_present' => true],
            'readability' => ['dense_block_count' => 0],
            'headings' => ['h1_present' => true],
            'trust_evidence' => ['overclaim_count' => 0],
            'brand_voice_fit' => ['guidance_available' => false],
        ],
        [
            'seo' => ['score' => 84],
            'readability' => ['score' => 79],
            'cta' => ['score' => 74],
            'structure' => ['score' => 81],
            'llm_visibility' => ['score' => 78],
            'brand_voice_fit' => ['score' => 76],
            'conversion_fit' => ['score' => 75],
            'trust_evidence' => ['score' => 78],
            'entities' => ['score' => 68],
        ],
    );

    expect($notReady['status_label'])->toBe('Not ready')
        ->and($notReady['blocking_issues'])->not->toBeEmpty()
        ->and($ready['status_label'])->toBe('Ready to publish');
});

function advancedMetricScoreForHtml(string $html, array $brief = [], array $snapshotOverrides = []): array
{
    $draft = new Draft([
        'title' => 'Draft intelligence article',
        'seo_title' => $snapshotOverrides['seo_title'] ?? 'Draft intelligence article',
        'seo_meta_description' => $snapshotOverrides['seo_meta_description'] ?? 'Draft intelligence article summary.',
        'seo_h1' => 'Draft intelligence article',
        'content_html' => $html,
    ]);
    $draft->setRelation('brief', new Brief(array_merge([
        'primary_keyword' => 'draft intelligence',
        'secondary_keywords' => ['workflow pilot'],
        'target_audience' => 'operations teams',
        'call_to_action' => 'Plan a pilot workshop',
        'funnel_stage' => 'consideration',
    ], $brief)));
    $draft->setRelation('articleEntities', new Collection());

    $parsed = app(DraftStructureParser::class)->parse($html);
    $snapshot = array_merge($parsed, [
        'title' => (string) $draft->title,
        'seo_title' => (string) $draft->seo_title,
        'seo_meta_description' => (string) $draft->seo_meta_description,
        'seo_h1' => (string) $draft->seo_h1,
        'content_html' => $html,
        'primary_keyword' => (string) ($draft->brief?->primary_keyword ?? ''),
        'secondary_keywords' => (array) ($draft->brief?->secondary_keywords ?? []),
        'target_audience' => (string) ($draft->brief?->target_audience ?? ''),
        'call_to_action' => (string) ($draft->brief?->call_to_action ?? ''),
        'funnel_stage' => (string) ($draft->brief?->funnel_stage ?? ''),
        'tone_of_voice' => (string) ($draft->brief?->tone_of_voice ?? ''),
        'expected_entities' => [(string) ($draft->brief?->primary_keyword ?? '')],
        'detected_entities' => [],
        'brand_voice' => $snapshotOverrides['brand_voice'] ?? [
            'preferred_terminology' => [],
            'disallowed_terminology' => [],
        ],
        'company_profile' => $snapshotOverrides['company_profile'] ?? [
            'value_propositions' => [],
            'proof_points' => [],
        ],
    ], $snapshotOverrides);

    $signals = app(DraftSignalExtractor::class)->extract($draft, $snapshot);

    return app(DraftMetricScorer::class)->score($snapshot, $signals);
}
