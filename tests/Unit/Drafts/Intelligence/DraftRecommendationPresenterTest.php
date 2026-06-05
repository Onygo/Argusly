<?php

use App\Models\DraftImprovementResult;
use App\Models\DraftIntelligenceDelta;
use App\Services\Drafts\Intelligence\DraftRecommendationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('formats delta displays with before after values and null safe fallbacks', function () {
    [, $draft] = makeDraftIntelligenceContext('presenter-delta-formatting');

    $result = DraftImprovementResult::query()->create([
        'draft_id' => (string) $draft->id,
        'action' => 'improve_full_draft',
        'status' => 'completed',
        'operation_key' => (string) Str::uuid(),
        'completed_at' => now(),
    ]);

    DraftIntelligenceDelta::query()->create([
        'draft_id' => (string) $draft->id,
        'draft_improvement_result_id' => (string) $result->id,
        'metric_key' => 'cta',
        'score_before' => 35,
        'score_after' => 61,
        'delta' => 26,
        'explanation' => 'CTA improved from 35 to 61 (+26).',
    ]);

    DraftIntelligenceDelta::query()->create([
        'draft_id' => (string) $draft->id,
        'draft_improvement_result_id' => (string) $result->id,
        'metric_key' => 'seo',
        'score_before' => 62,
        'score_after' => 62,
        'delta' => 0,
        'explanation' => 'SEO stayed flat at 62.',
    ]);

    DraftIntelligenceDelta::query()->create([
        'draft_id' => (string) $draft->id,
        'draft_improvement_result_id' => (string) $result->id,
        'metric_key' => 'llm_visibility',
        'score_before' => 48,
        'score_after' => null,
        'delta' => null,
        'explanation' => 'LLM Visibility was 48 before the improvement, but the latest rescan did not produce a new score yet.',
    ]);

    $deltaMap = app(DraftRecommendationPresenter::class)->deltaMapForImprovement($result);

    expect(data_get($deltaMap, 'cta.display_transition'))->toBe('35 → 61 (+26)')
        ->and(data_get($deltaMap, 'seo.display_transition'))->toBe('62 → 62 (0)')
        ->and(data_get($deltaMap, 'llm_visibility.display_transition'))->toBe('48 → n/a')
        ->and(data_get($deltaMap, 'llm_visibility.delta_value'))->toBeNull();
});

it('builds recent improvement history with stable timestamps and statuses', function () {
    [, $draft] = makeDraftIntelligenceContext('presenter-history-formatting');

    $result = DraftImprovementResult::query()->create([
        'draft_id' => (string) $draft->id,
        'action' => 'improve_full_draft',
        'status' => 'completed',
        'operation_key' => (string) Str::uuid(),
        'summary' => 'Added a stronger CTA.',
        'change_notes' => ['Added a stronger CTA.'],
        'score_delta_snapshot' => [
            'cta' => [
                'score_before' => 35,
                'score_after' => 61,
                'delta' => 26,
                'explanation' => 'CTA improved from 35 to 61 (+26).',
            ],
        ],
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
    ]);

    $history = app(DraftRecommendationPresenter::class)->recentImprovements([$result]);

    expect(data_get($history, '0.status_label'))->toBe('Completed')
        ->and(data_get($history, '0.displayed_at'))->not->toBeNull()
        ->and(data_get($history, '0.score_delta_snapshot.cta.display_transition'))->toBe('35 → 61 (+26)');
});
