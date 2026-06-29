<?php

use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingObjective;
use App\Models\Opportunity;
use App\Models\OpportunitySignal;
use App\Services\AgenticMarketing\OpportunityDetection\DetectedOpportunity;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticDetectorClassification;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function agenticMappingObjective(array $overrides = []): AgenticMarketingObjective
{
    $objective = new AgenticMarketingObjective;
    $objective->forceFill(array_merge([
        'id' => 'objective-1',
        'organization_id' => 10,
        'workspace_id' => 'workspace-1',
        'client_site_id' => 'site-1',
        'name' => 'AI visibility objective',
        'goal' => 'Grow AI visibility',
        'locale' => 'en',
        'status' => 'active',
    ], $overrides));

    return $objective;
}

function agenticDetectedOpportunity(array $payload = [], ?AgenticMarketingOpportunityType $type = null): DetectedOpportunity
{
    return new DetectedOpportunity(
        title: 'Improve AI visibility for answer content',
        type: $type ?? AgenticMarketingOpportunityType::AiVisibility,
        priorityScore: 82,
        payload: array_replace_recursive([
            'detector' => 'ai_visibility_gaps',
            'content_id' => 'content-1',
            'client_site_id' => 'site-1',
            'topic' => 'AI visibility',
            'signals' => [
                'ai_visibility_score' => 44,
                'topic_keyword' => 'AI visibility',
                'locale' => 'en',
            ],
            'score_explanation' => [
                'confidence_score' => 74,
                'impact_score' => 86,
                'effort_score' => 42,
                'risk_score' => 30,
                'summary' => 'Stored AI visibility metrics are weak.',
            ],
            'recommended_actions' => ['Draft answer-led improvements'],
        ], $payload),
        contentId: data_get($payload, 'content_id', 'content-1'),
    );
}

it('classifies every known Agentic detector output', function (): void {
    $mapper = app(AgenticOpportunityCanonicalMappingService::class);

    expect($mapper->detectorClassifications())->toMatchArray([
        'refresh_lifecycle' => AgenticDetectorClassification::SIGNAL_ONLY->value,
        'internal_links' => AgenticDetectorClassification::SIGNAL_ONLY->value,
        'localization_gaps' => AgenticDetectorClassification::SIGNAL_ONLY->value,
        'structured_answer_gaps' => AgenticDetectorClassification::SIGNAL_ONLY->value,
        'seo_indexability' => AgenticDetectorClassification::SIGNAL_ONLY->value,
        'content_network_gaps' => AgenticDetectorClassification::SIGNAL_AND_OPPORTUNITY->value,
        'ai_visibility_gaps' => AgenticDetectorClassification::SIGNAL_ONLY->value,
        'llm_tracking_ai_visibility' => AgenticDetectorClassification::SIGNAL_ONLY->value,
        'campaign_cluster_action_materializer' => AgenticDetectorClassification::SIGNAL_AND_OPPORTUNITY->value,
    ]);
});

it('maps signal-capable detector output into a canonical signal preview without persisting', function (): void {
    $mapper = app(AgenticOpportunityCanonicalMappingService::class);

    DB::connection()->enableQueryLog();

    $result = $mapper->map(agenticDetectedOpportunity(), agenticMappingObjective());

    expect(DB::getQueryLog())->toBe([])
        ->and($result->classification)->toBe(AgenticDetectorClassification::SIGNAL_ONLY)
        ->and($result->canEmitSignal)->toBeTrue()
        ->and($result->canEmitCanonicalOpportunityCandidate)->toBeFalse()
        ->and($result->signalPreview?->toArray())->toMatchArray([
            'workspace_id' => 'workspace-1',
            'client_site_id' => 'site-1',
            'content_id' => 'content-1',
            'objective_id' => 'objective-1',
            'source' => 'ai_citation_tracking',
            'detector_key' => 'ai_visibility_gaps',
            'opportunity_type' => 'ai_visibility',
            'topic' => 'AI visibility',
            'category' => 'ai_visibility_opportunity',
            'signal_strength' => 86.0,
            'confidence' => 74.0,
            'priority' => 82.0,
        ])
        ->and(Opportunity::query()->count())->toBe(0)
        ->and(OpportunitySignal::query()->count())->toBe(0);
});

it('maps signal-and-opportunity outputs into both previews', function (): void {
    $mapper = app(AgenticOpportunityCanonicalMappingService::class);
    $detected = agenticDetectedOpportunity([
        'detector' => 'content_network_gaps',
        'content_id' => null,
        'signals' => [
            'cluster_id' => 'cluster-1',
            'cluster_name' => 'AI visibility',
            'topic_keyword' => 'AI visibility',
            'gap_type' => 'missing_pillar',
        ],
    ], AgenticMarketingOpportunityType::ContentNetwork);

    $result = $mapper->map($detected, agenticMappingObjective(), 'content_network_gaps');

    expect($result->classification)->toBe(AgenticDetectorClassification::SIGNAL_AND_OPPORTUNITY)
        ->and($result->canEmitSignal)->toBeTrue()
        ->and($result->canEmitCanonicalOpportunityCandidate)->toBeTrue()
        ->and($result->opportunityPreview?->toArray())->toMatchArray([
            'title' => 'Improve AI visibility for answer content',
            'category' => 'content_gap',
            'type' => 'content_network',
            'workspace_id' => 'workspace-1',
            'client_site_id' => 'site-1',
            'objective_id' => 'objective-1',
            'priority' => 82.0,
            'confidence' => 74.0,
            'impact' => 86.0,
            'effort' => 42.0,
        ]);
});

it('reports missing context and blocks unsafe materialized outputs', function (): void {
    $mapper = app(AgenticOpportunityCanonicalMappingService::class);
    $detected = agenticDetectedOpportunity([
        'detector' => 'campaign_cluster_action_materializer',
        'dedupe_key' => null,
        'signals' => [
            'topic_keyword' => 'AI visibility campaign',
        ],
    ], AgenticMarketingOpportunityType::NewArticle);

    $result = $mapper->map($detected, agenticMappingObjective(['workspace_id' => null]), 'campaign_cluster_action_materializer');

    expect($result->canEmitSignal)->toBeFalse()
        ->and($result->canEmitCanonicalOpportunityCandidate)->toBeFalse()
        ->and($result->missingContext)->toContain('workspace_id', 'campaign_cluster_id')
        ->and($result->blockedReasons)->toContain('campaign_cluster_materialization_requires_stable_payload_dedupe_key')
        ->and($result->riskLevel)->toBe('high');
});

it('keeps dedupe keys stable across volatile timestamps and score refreshes', function (): void {
    $mapper = app(AgenticOpportunityCanonicalMappingService::class);
    $objective = agenticMappingObjective();

    $first = $mapper->map(agenticDetectedOpportunity([
        'signals' => ['latest_snapshot' => ['captured_at' => '2026-01-01T00:00:00Z']],
        'score_explanation' => ['impact_score' => 65, 'confidence_score' => 55],
    ]), $objective);
    $second = $mapper->map(agenticDetectedOpportunity([
        'signals' => ['latest_snapshot' => ['captured_at' => '2026-06-01T00:00:00Z']],
        'score_explanation' => ['impact_score' => 88, 'confidence_score' => 81],
    ]), $objective);

    expect($first->dedupeKey)->toBe($second->dedupeKey);
});

it('changes dedupe keys by workspace objective site and content context', function (): void {
    $mapper = app(AgenticOpportunityCanonicalMappingService::class);
    $base = $mapper->map(agenticDetectedOpportunity(), agenticMappingObjective())->dedupeKey;

    expect($mapper->map(agenticDetectedOpportunity(), agenticMappingObjective(['workspace_id' => 'workspace-2']))->dedupeKey)->not->toBe($base)
        ->and($mapper->map(agenticDetectedOpportunity(), agenticMappingObjective(['id' => 'objective-2']))->dedupeKey)->not->toBe($base)
        ->and($mapper->map(agenticDetectedOpportunity(['client_site_id' => 'site-2']), agenticMappingObjective(['client_site_id' => 'site-2']))->dedupeKey)->not->toBe($base)
        ->and($mapper->map(agenticDetectedOpportunity(['content_id' => 'content-2']), agenticMappingObjective())->dedupeKey)->not->toBe($base);
});
