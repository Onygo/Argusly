<?php

use App\Enums\AgenticMarketingActionType;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionPipeline;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerReadinessInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3lContext(string $slug = 'phase-3l'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3L '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3L Workspace',
        'display_name' => 'Phase 3L Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3L Site',
        'site_url' => 'https://phase-3l.test',
        'base_url' => 'https://phase-3l.test',
        'allowed_domains' => ['phase-3l.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3L objective',
        'goal' => 'Inspect planner readiness',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3lOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3L planner readiness',
        'type' => 'content_network',
        'priority_score' => 91,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Planner readiness diagnostics',
            'reasoning' => 'Canonical context should be inspectable before planner selection changes.',
            'recommendation' => 'Keep the planner legacy-owned.',
            'primary_search_intent' => 'implementation',
            'target_audience' => 'content teams',
            'signals' => [
                'cluster_id' => 'phase-3l',
                'cluster_name' => 'Planner readiness diagnostics',
                'topic_keyword' => 'Planner readiness diagnostics',
                'gap_type' => 'missing_pillar',
            ],
            'score_explanation' => [
                'summary' => 'Readiness is diagnostic only.',
                'impact_score' => 87,
                'confidence_score' => 81,
                'effort_score' => 43,
            ],
        ],
    ], $overrides));
}

function phase3lCanonical(AgenticMarketingOpportunity $legacy, array $overrides = []): Opportunity
{
    $legacy->loadMissing('objective');

    return Opportunity::factory()->create(array_merge([
        'organization_id' => $legacy->objective->organization_id,
        'workspace_id' => $legacy->objective->workspace_id,
        'client_site_id' => $legacy->objective->client_site_id,
        'agentic_marketing_opportunity_id' => $legacy->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical Phase 3L planner readiness',
        'topic' => 'Planner readiness diagnostics',
        'summary' => 'Linked canonical context for readiness inspection.',
        'priority_score' => 84,
        'recommended_actions' => [['title' => 'Do not create canonical Agentic actions']],
        'source_signal_summary' => [
            'detector_key' => 'content_network_gaps',
            'objective_id' => (string) $legacy->objective_id,
            'opportunity_type' => (string) $legacy->type,
        ],
        'metadata' => [
            'objective_id' => (string) $legacy->objective_id,
            'detector_key' => 'content_network_gaps',
            'agentic_type' => (string) $legacy->type,
            'source_scoped_dedupe_key' => (string) $legacy->dedupe_hash,
        ],
        'dedupe_hash' => (string) $legacy->dedupe_hash,
    ], $overrides));
}

function phase3lAction(AgenticMarketingOpportunity $opportunity, array $overrides = []): AgenticMarketingAction
{
    $opportunity->loadMissing('objective');

    return AgenticMarketingAction::query()->create(array_replace_recursive([
        'objective_id' => (string) $opportunity->objective_id,
        'opportunity_id' => (string) $opportunity->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 30,
        'payload' => [
            'workspace_id' => (string) $opportunity->objective->workspace_id,
            'client_site_id' => (string) $opportunity->objective->client_site_id,
            'title' => 'Planner readiness diagnostics',
            'planning' => [
                'planner' => AgenticMarketingActionPlanner::class,
                'source_opportunity_type' => (string) $opportunity->type,
            ],
        ],
    ], $overrides));
}

it('reports legacy-only Agentic opportunities as legacy_only', function (): void {
    [, , , $objective] = phase3lContext('phase-3l-legacy-only');
    $legacy = phase3lOpportunity($objective);

    $report = app(AgenticPlannerReadinessInspectionService::class)->inspect($legacy);

    expect($report['readiness_status'])->toBe(AgenticPlannerReadinessInspectionService::STATUS_LEGACY_ONLY)
        ->and($report['linked_canonical_opportunity_id'])->toBeNull()
        ->and($report['readiness_blocked_reasons'])->toContain('missing_safe_canonical_bridge');
});

it('reports safe canonical bridge context as available without making planner selection ready', function (): void {
    [, , , $objective] = phase3lContext('phase-3l-context');
    $legacy = phase3lOpportunity($objective);
    phase3lCanonical($legacy, ['metadata' => []]);

    $report = app(AgenticPlannerReadinessInspectionService::class)->inspect($legacy);

    expect($report['readiness_status'])->toBe(AgenticPlannerReadinessInspectionService::STATUS_CANONICAL_CONTEXT_AVAILABLE)
        ->and($report['phase_3k_metadata_availability_for_future_rows']['available'])->toBeFalse()
        ->and($report['current_planner_eligibility']['selection_authority'])->toBe('agentic_marketing_opportunities');
});

it('reports Phase 3K metadata alone as metadata_ready_only rather than planner ready', function (): void {
    [, , , $objective] = phase3lContext('phase-3l-metadata-only');
    $legacy = phase3lOpportunity($objective);
    phase3lCanonical($legacy);

    $report = app(AgenticPlannerReadinessInspectionService::class)->inspect($legacy);

    expect($report['phase_3k_metadata_availability_for_future_rows']['available'])->toBeTrue()
        ->and($report['readiness_status'])->toBe(AgenticPlannerReadinessInspectionService::STATUS_METADATA_READY_ONLY)
        ->and($report['readiness_blocked_reasons'])->toContain('phase_3j_lifecycle_status_ambiguous');
});

it('blocks readiness when duplicate canonical bridges exist', function (): void {
    [, , , $objective] = phase3lContext('phase-3l-duplicate-bridges');
    $legacy = phase3lOpportunity($objective);
    phase3lCanonical($legacy, ['dedupe_hash' => 'phase-3l-one-'.Str::random(8)]);
    phase3lCanonical($legacy, ['dedupe_hash' => 'phase-3l-two-'.Str::random(8)]);

    $report = app(AgenticPlannerReadinessInspectionService::class)->inspect($legacy);

    expect($report['readiness_status'])->toBe(AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_BLOCKED)
        ->and($report['readiness_blocked_reasons'])->toContain('multiple_canonical_opportunities_linked_to_agentic_row');
});

it('blocks readiness when Phase 3H signatures are blocked', function (): void {
    [, , , $objective] = phase3lContext('phase-3l-signature-blocked');
    $legacy = phase3lOpportunity($objective, [
        'payload' => [
            'detector' => 'unknown',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Unknown detector planner readiness',
            'signals' => ['topic_keyword' => 'Unknown detector planner readiness'],
        ],
    ]);
    phase3lCanonical($legacy);

    $report = app(AgenticPlannerReadinessInspectionService::class)->inspect($legacy);

    expect($report['readiness_status'])->toBe(AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_BLOCKED)
        ->and($report['phase_3h_signature_status']['blocked_reasons'])->toContain('missing_detector_key');
});

it('blocks readiness when Phase 3I continuity has canonical-parent-only lookup blockers', function (): void {
    [, , , $objective] = phase3lContext('phase-3l-continuity');
    $legacy = phase3lOpportunity($objective, ['status' => 'dismissed']);
    phase3lCanonical($legacy, ['status' => OpportunityStatus::DISMISSED]);
    AgenticMarketingExecutionPipeline::query()->create([
        'organization_id' => $objective->organization_id,
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'mode' => 'manual',
        'status' => 'running',
        'current_stage' => 'asset_generation',
        'approval_status' => 'pending',
        'publishing_readiness' => 'not_ready',
    ]);

    $report = app(AgenticPlannerReadinessInspectionService::class)->inspect($legacy);

    expect($report['readiness_status'])->toBe(AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_BLOCKED)
        ->and($report['phase_3i_continuity_status']['canonical_parent_only_lookup_blockers'])
        ->toContain('canonical_parent_only_lookup_would_miss_execution_pipelines');
});

it('blocks readiness when Phase 3J lifecycle ambiguity is present', function (): void {
    [, , , $objective] = phase3lContext('phase-3l-lifecycle');
    $legacy = phase3lOpportunity($objective, ['status' => 'completed']);
    phase3lCanonical($legacy, ['status' => OpportunityStatus::ACTIONED]);

    $report = app(AgenticPlannerReadinessInspectionService::class)->inspect($legacy);

    expect($report['readiness_status'])->toBe(AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_BLOCKED)
        ->and($report['phase_3j_lifecycle_action_ownership_status']['lifecycle_status_ambiguous'])->toBeTrue()
        ->and($report['readiness_blocked_reasons'])->toContain('phase_3j_lifecycle_status_ambiguous');
});

it('blocks readiness when an open legacy action would be duplicated', function (): void {
    [, , , $objective] = phase3lContext('phase-3l-duplicate-action');
    $legacy = phase3lOpportunity($objective, ['status' => 'dismissed']);
    phase3lCanonical($legacy, ['status' => OpportunityStatus::DISMISSED]);
    phase3lAction($legacy);

    $report = app(AgenticPlannerReadinessInspectionService::class)->inspect($legacy);

    expect($report['readiness_status'])->toBe(AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_BLOCKED)
        ->and($report['duplicate_action_risk']['risk'])->toBeTrue()
        ->and($report['readiness_blocked_reasons'])->toContain('canonical_action_would_duplicate_open_legacy_action');
});

it('reports ready status only when all guarded readiness blockers are clear', function (): void {
    [, , , $objective] = phase3lContext('phase-3l-ready');
    $legacy = phase3lOpportunity($objective, ['status' => 'dismissed']);
    phase3lCanonical($legacy, ['status' => OpportunityStatus::DISMISSED]);

    $report = app(AgenticPlannerReadinessInspectionService::class)->inspect($legacy);

    expect($report['readiness_status'])->toBe(AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY)
        ->and($report['readiness_blocked_reasons'])->toBe([])
        ->and($report['current_planner_eligibility']['eligible'])->toBeFalse();
});

it('keeps the planner readiness command read-only', function (): void {
    [, $workspace, , $objective] = phase3lContext('phase-3l-command');
    $legacy = phase3lOpportunity($objective);
    $canonical = phase3lCanonical($legacy);
    $legacyUpdatedAt = $legacy->updated_at?->toIso8601String();
    $canonicalUpdatedAt = $canonical->updated_at?->toIso8601String();
    $actionCount = AgenticMarketingAction::query()->count();
    $canonicalCount = Opportunity::query()->count();

    DB::enableQueryLog();

    $this->artisan('mos:inspect-agentic-planner-readiness', [
        '--workspace' => (string) $workspace->id,
        '--limit' => 5,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Agentic planner readiness diagnostics.')
        ->expectsOutputToContain('inspected count: 1')
        ->expectsOutputToContain('metadata ready only count: 1')
        ->expectsOutputToContain('sample legacy vs canonical priority differences:')
        ->expectsOutputToContain('blocked reason samples:')
        ->expectsOutputToContain('readiness samples:');

    $writeQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $query) === 1)
        ->values();

    expect($writeQueries)->toHaveCount(0)
        ->and($legacy->refresh()->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and($canonical->refresh()->updated_at?->toIso8601String())->toBe($canonicalUpdatedAt)
        ->and(AgenticMarketingAction::query()->count())->toBe($actionCount)
        ->and(Opportunity::query()->count())->toBe($canonicalCount);
});

it('does not change AgenticMarketingActionPlanner behaviour or canonical action ownership', function (): void {
    [, , , $objective] = phase3lContext('phase-3l-planner');
    $legacy = phase3lOpportunity($objective);
    $canonical = phase3lCanonical($legacy);
    $canonicalActionsBefore = $canonical->recommended_actions;

    app(AgenticPlannerReadinessInspectionService::class)->inspect($legacy);
    $summary = app(AgenticMarketingActionPlanner::class)->planForOpportunity($legacy);

    $actions = AgenticMarketingAction::query()->where('objective_id', $objective->id)->get();

    expect($summary['created'])->toBeGreaterThan(0)
        ->and($actions)->not->toBeEmpty()
        ->and($actions->pluck('opportunity_id')->unique()->values()->all())->toBe([(string) $legacy->id])
        ->and($actions->pluck('opportunity_id')->unique()->values()->all())->not->toContain((string) $canonical->id)
        ->and($canonical->refresh()->recommended_actions)->toBe($canonicalActionsBefore);
});
