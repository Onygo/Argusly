<?php

use App\Enums\ProgrammaticPatternType;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionPipeline;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\GrowthAsset;
use App\Models\GrowthProgram;
use App\Models\Opportunity;
use App\Models\OpportunitySignal;
use App\Models\Organization;
use App\Models\ProgrammaticOpportunity;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticDetectorClassification;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityBridgeEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function agenticBridgeFixture(array $objectiveOverrides = []): array
{
    $organization = Organization::query()->create([
        'name' => 'Agentic Bridge Org',
        'slug' => 'agentic-bridge-org-'.str()->random(8),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Agentic Bridge Workspace',
        'display_name' => 'Agentic Bridge Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Agentic Bridge Site',
        'site_url' => 'https://agentic-bridge.test',
        'base_url' => 'https://agentic-bridge.test',
        'allowed_domains' => ['agentic-bridge.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create(array_merge([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Bridge readiness objective',
        'goal' => 'Inspect Agentic bridge readiness',
        'locale' => 'en',
        'status' => 'active',
    ], $objectiveOverrides));

    return [$organization, $workspace, $site, $objective];
}

function agenticBridgeOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Improve AI visibility',
        'type' => 'ai_visibility',
        'priority_score' => 81,
        'status' => 'open',
        'payload' => [
            'detector' => 'ai_visibility_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'AI visibility',
            'signals' => [
                'topic_keyword' => 'AI visibility',
                'ai_visibility_score' => 42,
                'locale' => 'en',
            ],
            'score_explanation' => [
                'impact_score' => 84,
                'confidence_score' => 72,
                'effort_score' => 44,
            ],
        ],
    ], $overrides));
}

it('reports a signal-ready Agentic row without mutating canonical tables', function (): void {
    [, , , $objective] = agenticBridgeFixture();
    $opportunity = agenticBridgeOpportunity($objective);

    DB::enableQueryLog();

    $result = app(AgenticOpportunityBridgeEligibilityService::class)->inspect($opportunity);
    $writeQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $query) === 1)
        ->values();

    expect($result->eligibilityStatus)->toBe(AgenticOpportunityBridgeEligibilityService::STATUS_SIGNAL_READY)
        ->and($result->phase3bDetectorClassification)->toBe(AgenticDetectorClassification::SIGNAL_ONLY)
        ->and($result->signalEligibility)->toBeTrue()
        ->and($result->canonicalOpportunityEligibility)->toBeFalse()
        ->and($result->blockedReasons)->toBe([])
        ->and($writeQueries)->toHaveCount(0)
        ->and(Opportunity::query()->count())->toBe(0)
        ->and(OpportunitySignal::query()->count())->toBe(0);
});

it('reports a signal-and-canonical-ready Agentic row', function (): void {
    [, , , $objective] = agenticBridgeFixture();
    $opportunity = agenticBridgeOpportunity($objective, [
        'title' => 'Build missing AI visibility cluster',
        'type' => 'content_network',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'AI visibility',
            'signals' => [
                'cluster_id' => 'cluster-1',
                'cluster_name' => 'AI visibility',
                'topic_keyword' => 'AI visibility',
                'gap_type' => 'missing_pillar',
            ],
        ],
    ]);

    $result = app(AgenticOpportunityBridgeEligibilityService::class)->inspect($opportunity);

    expect($result->eligibilityStatus)->toBe(AgenticOpportunityBridgeEligibilityService::STATUS_SIGNAL_AND_CANONICAL_READY)
        ->and($result->phase3bDetectorClassification)->toBe(AgenticDetectorClassification::SIGNAL_AND_OPPORTUNITY)
        ->and($result->signalEligibility)->toBeTrue()
        ->and($result->canonicalOpportunityEligibility)->toBeTrue()
        ->and($result->mappingResult->canEmitCanonicalOpportunityCandidate)->toBeTrue();
});

it('blocks rows with missing required bridge context', function (): void {
    [, , , $objective] = agenticBridgeFixture(['workspace_id' => null]);
    $opportunity = agenticBridgeOpportunity($objective, [
        'title' => '',
        'type' => null,
        'payload' => [
            'detector' => 'unknown_detector',
            'signals' => [],
        ],
    ]);

    $result = app(AgenticOpportunityBridgeEligibilityService::class)->inspect($opportunity);

    expect($result->eligibilityStatus)->toBe(AgenticOpportunityBridgeEligibilityService::STATUS_MISSING_CONTEXT)
        ->and($result->signalEligibility)->toBeFalse()
        ->and($result->canonicalOpportunityEligibility)->toBeFalse()
        ->and($result->blockedReasons)->toContain('missing_workspace_id', 'missing_opportunity_type', 'missing_title');
});

it('detects duplicate canonical bridge risk', function (): void {
    [, $workspace, , $objective] = agenticBridgeFixture();
    $opportunity = agenticBridgeOpportunity($objective);

    Opportunity::factory()->create([
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'agentic_marketing_opportunity_id' => $opportunity->id,
        'dedupe_hash' => hash('sha256', 'bridge-one'),
    ]);
    Opportunity::factory()->create([
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'agentic_marketing_opportunity_id' => $opportunity->id,
        'dedupe_hash' => hash('sha256', 'bridge-two'),
    ]);

    $result = app(AgenticOpportunityBridgeEligibilityService::class)->inspect($opportunity);

    expect($result->eligibilityStatus)->toBe(AgenticOpportunityBridgeEligibilityService::STATUS_DUPLICATE_RISK)
        ->and($result->duplicateBridgeRisk)->toBeTrue()
        ->and($result->existingLinkedCanonicalOpportunityIds)->toHaveCount(2)
        ->and($result->blockedReasons)->toContain('multiple_canonical_opportunities_linked_to_agentic_row');
});

it('detects dedupe-matched canonical opportunity candidates without a bridge', function (): void {
    [, $workspace, , $objective] = agenticBridgeFixture();
    $opportunity = agenticBridgeOpportunity($objective, [
        'type' => 'content_network',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'AI visibility',
            'signals' => [
                'cluster_id' => 'cluster-1',
                'cluster_name' => 'AI visibility',
                'topic_keyword' => 'AI visibility',
            ],
        ],
    ]);
    $initial = app(AgenticOpportunityBridgeEligibilityService::class)->inspect($opportunity);

    Opportunity::factory()->create([
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'agentic_marketing_opportunity_id' => null,
        'dedupe_hash' => $initial->mappingResult->dedupeKey,
    ]);

    $result = app(AgenticOpportunityBridgeEligibilityService::class)->inspect($opportunity);

    expect($result->eligibilityStatus)->toBe(AgenticOpportunityBridgeEligibilityService::STATUS_DUPLICATE_RISK)
        ->and($result->duplicateStrategicOpportunityRisk)->toBeTrue()
        ->and($result->dedupeMatchedCanonicalOpportunityCandidates)->toHaveCount(1)
        ->and($result->blockedReasons)->toContain('canonical_opportunity_dedupe_match_without_bridge');
});

it('reports open actions and execution pipelines as execution-state dependencies', function (): void {
    [, , , $objective] = agenticBridgeFixture();
    $opportunity = agenticBridgeOpportunity($objective, [
        'type' => 'content_network',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'AI visibility',
            'signals' => [
                'cluster_id' => 'cluster-1',
                'cluster_name' => 'AI visibility',
                'topic_keyword' => 'AI visibility',
            ],
        ],
    ]);

    AgenticMarketingAction::query()->create([
        'objective_id' => $objective->id,
        'opportunity_id' => $opportunity->id,
        'action_type' => 'create_article',
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'payload' => ['headline' => 'AI visibility guide'],
    ]);
    AgenticMarketingExecutionPipeline::query()->create([
        'organization_id' => $objective->organization_id,
        'objective_id' => $objective->id,
        'opportunity_id' => $opportunity->id,
        'mode' => 'guided',
        'status' => 'running',
        'current_stage' => 'draft',
        'approval_status' => 'pending',
        'publishing_readiness' => 'blocked',
        'input' => [],
        'result' => [],
    ]);

    $result = app(AgenticOpportunityBridgeEligibilityService::class)->inspect($opportunity);

    expect($result->eligibilityStatus)->toBe(AgenticOpportunityBridgeEligibilityService::STATUS_EXECUTION_BLOCKED)
        ->and($result->signalEligibility)->toBeTrue()
        ->and($result->canonicalOpportunityEligibility)->toBeFalse()
        ->and($result->openAgenticActionsCount)->toBe(1)
        ->and($result->executionPipelineCount)->toBe(1)
        ->and($result->executionBlockerStatus)->toBe('execution_state_dependent');
});

it('reports growth and programmatic references to the Agentic row', function (): void {
    [, $workspace, , $objective] = agenticBridgeFixture();
    $opportunity = agenticBridgeOpportunity($objective, [
        'type' => 'content_network',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'AI visibility',
            'signals' => [
                'cluster_id' => 'cluster-1',
                'cluster_name' => 'AI visibility',
                'topic_keyword' => 'AI visibility',
            ],
        ],
    ]);

    $program = GrowthProgram::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'name' => 'AI visibility growth',
        'description' => 'Inspect bridge references',
        'status' => 'detected',
        'score' => 70,
    ]);

    GrowthAsset::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'role' => GrowthAsset::ROLE_AGENTIC_OPPORTUNITY,
        'assetable_type' => AgenticMarketingOpportunity::class,
        'assetable_id' => $opportunity->id,
        'status_at_link' => 'open',
        'source_type' => 'agentic_marketing',
        'metadata' => ['agentic_marketing_opportunity_id' => (string) $opportunity->id],
    ]);

    ProgrammaticOpportunity::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'source_type' => AgenticMarketingOpportunity::class,
        'source_id' => $opportunity->id,
        'pattern_type' => ProgrammaticPatternType::AI_ANSWER_LIBRARY,
        'base_topic' => 'AI visibility',
        'status' => ProgrammaticOpportunity::STATUS_DETECTED,
        'metadata' => ['agentic_marketing_opportunity_id' => (string) $opportunity->id],
    ]);

    $result = app(AgenticOpportunityBridgeEligibilityService::class)->inspect($opportunity);

    expect($result->eligibilityStatus)->toBe(AgenticOpportunityBridgeEligibilityService::STATUS_EXECUTION_BLOCKED)
        ->and($result->growthAssetCount)->toBe(1)
        ->and($result->programmaticOpportunityCount)->toBe(1)
        ->and($result->executionBlockerStatus)->toBe('execution_state_dependent');
});

it('reports diagnostics command output without creating canonical records', function (): void {
    [, $workspace, , $objective] = agenticBridgeFixture();
    agenticBridgeOpportunity($objective);

    $this->artisan('mos:inspect-agentic-opportunity-bridges', [
        '--workspace' => (string) $workspace->id,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Agentic Marketing opportunity bridge eligibility diagnostics')
        ->expectsOutputToContain('total inspected count: 1')
        ->expectsOutputToContain('signal ready count: 1')
        ->expectsOutputToContain('dedupe key samples:')
        ->expectsOutputToContain('ai_visibility_gaps');

    expect(Opportunity::query()->count())->toBe(0)
        ->and(OpportunitySignal::query()->count())->toBe(0);
});
