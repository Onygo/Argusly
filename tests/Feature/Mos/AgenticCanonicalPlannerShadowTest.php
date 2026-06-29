<?php

use App\Enums\AgenticMarketingActionType;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingAuditLog;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingRun;
use App\Models\AgenticMarketingRunItem;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerApplyExperimentService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerExperimentService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerShadowService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityActionSignatureService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerApplyExperimentAuditService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerReadinessInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3pContext(string $slug = 'phase-3p'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3P '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3P Workspace',
        'display_name' => 'Phase 3P Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3P Site',
        'site_url' => 'https://phase-3p.test',
        'base_url' => 'https://phase-3p.test',
        'allowed_domains' => ['phase-3p.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3P objective',
        'goal' => 'Shadow canonical planner selection',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3pOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    $token = 'phase-3p-'.Str::lower(Str::random(8));

    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3P planner shadow',
        'type' => 'content_network',
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Planner shadow contract '.$token,
            'reasoning' => 'Canonical shadow diagnostics should not change planner ownership.',
            'recommendation' => 'Keep legacy planner behaviour.',
            'primary_search_intent' => 'implementation',
            'target_audience' => 'content teams',
            'signals' => [
                'cluster_id' => $token,
                'cluster_name' => 'Planner shadow contract '.$token,
                'topic_keyword' => 'Planner shadow contract '.$token,
                'gap_type' => 'missing_pillar',
            ],
            'score_explanation' => [
                'summary' => 'Shadow comparison is diagnostic only.',
                'impact_score' => 80,
                'confidence_score' => 75,
                'effort_score' => 45,
            ],
        ],
    ], $overrides));
}

function phase3pCanonical(AgenticMarketingOpportunity $legacy, array $overrides = []): Opportunity
{
    $legacy->loadMissing('objective');

    return Opportunity::factory()->create(array_merge([
        'organization_id' => $legacy->objective->organization_id,
        'workspace_id' => $legacy->objective->workspace_id,
        'client_site_id' => $legacy->objective->client_site_id,
        'agentic_marketing_opportunity_id' => $legacy->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::DISMISSED,
        'title' => 'Canonical Phase 3P planner shadow',
        'topic' => 'Planner shadow contract',
        'summary' => 'Linked canonical context for shadow diagnostics.',
        'priority_score' => 70,
        'recommended_actions' => [['title' => 'Do not create canonical actions']],
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

function phase3pAction(AgenticMarketingOpportunity $opportunity, array $overrides = []): AgenticMarketingAction
{
    $opportunity->loadMissing('objective');

    return AgenticMarketingAction::query()->create(array_replace_recursive([
        'objective_id' => (string) $opportunity->objective_id,
        'opportunity_id' => (string) $opportunity->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'workspace_id' => (string) $opportunity->objective->workspace_id,
            'client_site_id' => (string) $opportunity->objective->client_site_id,
            'title' => (string) data_get($opportunity->payload, 'signals.topic_keyword', 'Planner shadow contract'),
            'planning' => [
                'planner' => AgenticMarketingActionPlanner::class,
                'source_opportunity_type' => (string) $opportunity->type,
            ],
        ],
    ], $overrides));
}

function phase3pAppliedAction(AgenticMarketingObjective $objective, array $metadataOverrides = []): array
{
    $legacy = phase3pOpportunity($objective, ['status' => 'dismissed']);
    $canonical = phase3pCanonical($legacy);
    $action = phase3pAction($legacy);
    $signature = app(AgenticOpportunityActionSignatureService::class)
        ->forCanonicalActionCandidate($canonical, (string) $action->action_type);
    $payload = (array) $action->payload;
    $payload['planner_experiment'] = array_replace_recursive([
        'version' => AgenticCanonicalPlannerApplyExperimentService::METADATA_VERSION,
        'canonical_opportunity_id' => (string) $canonical->id,
        'legacy_agentic_marketing_opportunity_id' => (string) $legacy->id,
        'objective_id' => (string) $objective->id,
        'workspace_id' => (string) $objective->workspace_id,
        'selection_source' => 'canonical_experiment',
        'phase_3m_signature' => $signature['signature'],
        'phase_3l_readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY,
        'applied_at' => now()->toIso8601String(),
        'applied_by' => 'command',
    ], $metadataOverrides);

    DB::table($action->getTable())
        ->where('id', $action->id)
        ->update(['payload' => json_encode($payload, JSON_UNESCAPED_SLASHES)]);

    return [$legacy, $canonical, $action->refresh()];
}

it('keeps the canonical planner shadow feature flag off by default', function (): void {
    expect(config('features.mos_agentic_planner_canonical_shadow'))->toBeFalse();
});

it('returns legacy and canonical orders separately without planner side effects', function (): void {
    [, , , $objective] = phase3pContext('phase-3p-report');
    $legacy = phase3pOpportunity($objective, ['status' => 'dismissed']);
    $canonical = phase3pCanonical($legacy);
    $canonicalActionsBefore = $canonical->recommended_actions;
    $counts = [
        'actions' => AgenticMarketingAction::query()->count(),
        'runs' => AgenticMarketingRun::query()->count(),
        'run_items' => AgenticMarketingRunItem::query()->count(),
        'audit_logs' => AgenticMarketingAuditLog::query()->count(),
        'canonical' => Opportunity::query()->count(),
    ];

    $report = app(AgenticCanonicalPlannerShadowService::class)->compare($objective);

    expect($report['legacy_order'])->toBe([])
        ->and(collect($report['shadow_canonical_order'])->pluck('legacy_opportunity_id')->all())->toBe([(string) $legacy->id])
        ->and($report['summary']['shadow_canonical_candidate_count'])->toBe(1)
        ->and($report['summary']['shadow_safe_objective_count'])->toBe(1)
        ->and($report['summary']['recommendation'])->toBe('continue shadow')
        ->and($report['expected_created_or_reused_action_types']['shadow_canonical'])->toBe([AgenticMarketingActionType::CreateArticle->value])
        ->and(AgenticMarketingAction::query()->count())->toBe($counts['actions'])
        ->and(AgenticMarketingRun::query()->count())->toBe($counts['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($counts['run_items'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($counts['audit_logs'])
        ->and(Opportunity::query()->count())->toBe($counts['canonical'])
        ->and($canonical->refresh()->recommended_actions)->toBe($canonicalActionsBefore);
});

it('excludes non Phase 3L ready rows and reports blockers', function (): void {
    [, , , $objective] = phase3pContext('phase-3p-non-ready');
    $legacy = phase3pOpportunity($objective);
    phase3pCanonical($legacy, ['status' => OpportunityStatus::OPEN]);

    $report = app(AgenticCanonicalPlannerShadowService::class)->compare($objective);

    expect($report['shadow_canonical_order'])->toBe([])
        ->and($report['summary']['legacy_candidate_count'])->toBe(1)
        ->and($report['summary']['blocked_canonical_candidate_count'])->toBe(1)
        ->and($report['summary']['lifecycle_risk_count'])->toBe(1)
        ->and($report['summary']['recommendation'])->toBe('blocked');
});

it('blocks rows with risky Phase 3O audit status', function (): void {
    [, , , $objective] = phase3pContext('phase-3p-3o-risk');
    phase3pAppliedAction($objective, ['phase_3m_signature' => hash('sha256', 'stale-shadow-signature')]);

    $report = app(AgenticCanonicalPlannerShadowService::class)->compare($objective);

    expect($report['summary']['phase_3o_risky_count'])->toBe(1)
        ->and($report['summary']['signature_mismatch_count'])->toBe(1)
        ->and($report['summary']['blocked_objective_count'])->toBe(1)
        ->and($report['summary']['recommendation'])->toBe('blocked')
        ->and($report['phase_3o_risky_rows'][0]['audit_status'])->toBe(AgenticPlannerApplyExperimentAuditService::STATUS_SIGNATURE_MISMATCH);
});

it('reports duplicate, continuity and lifecycle risks', function (): void {
    [, , , $objective] = phase3pContext('phase-3p-risk-counters');
    $duplicate = phase3pOpportunity($objective, ['status' => 'dismissed']);
    phase3pCanonical($duplicate);
    phase3pAction($duplicate);

    $continuity = phase3pOpportunity($objective, ['status' => 'dismissed']);
    phase3pCanonical($continuity);
    phase3pAction($continuity, ['status' => AgenticMarketingAction::STATUS_COMPLETED]);

    $lifecycle = phase3pOpportunity($objective, ['status' => 'open']);
    phase3pCanonical($lifecycle, ['status' => OpportunityStatus::OPEN]);

    $report = app(AgenticCanonicalPlannerShadowService::class)->compare($objective);

    expect($report['summary']['duplicate_risk_count'])->toBeGreaterThanOrEqual(1)
        ->and($report['summary']['continuity_risk_count'])->toBeGreaterThanOrEqual(1)
        ->and($report['summary']['lifecycle_risk_count'])->toBeGreaterThanOrEqual(1)
        ->and($report['summary']['blocked_objective_count'])->toBe(1);
});

it('reports priority order differences from the shadow comparison DTO', function (): void {
    [, , , $objective] = phase3pContext('phase-3p-priority-diff');

    $experiment = new class extends AgenticCanonicalPlannerExperimentService
    {
        public function __construct() {}

        public function compare(AgenticMarketingObjective $objective, array $filters = []): array
        {
            return [
                'objective_id' => (string) $objective->id,
                'workspace_id' => (string) $objective->workspace_id,
                'site_id' => (string) $objective->client_site_id,
                'summary' => [
                    'duplicate_risk_count' => 0,
                    'continuity_blocker_count' => 0,
                    'lifecycle_blocker_count' => 0,
                ],
                'legacy_order' => [
                    ['rank' => 1, 'legacy_opportunity_id' => 'legacy-a', 'priority_score' => 90, 'action_types' => ['create_article']],
                    ['rank' => 2, 'legacy_opportunity_id' => 'legacy-b', 'priority_score' => 40, 'action_types' => ['update_meta']],
                ],
                'canonical_experiment_order' => [
                    ['rank' => 1, 'legacy_opportunity_id' => 'legacy-b', 'canonical_opportunity_id' => 'canonical-b', 'canonical_priority_score' => 95, 'action_types' => ['update_meta']],
                    ['rank' => 2, 'legacy_opportunity_id' => 'legacy-a', 'canonical_opportunity_id' => 'canonical-a', 'canonical_priority_score' => 50, 'action_types' => ['create_article']],
                ],
                'excluded_rows' => [],
                'priority_order_differences' => [
                    ['legacy_opportunity_id' => 'legacy-b', 'legacy_rank' => 2, 'canonical_rank' => 1],
                    ['legacy_opportunity_id' => 'legacy-a', 'legacy_rank' => 1, 'canonical_rank' => 2],
                ],
                'action_signature_equivalence' => [],
                'readiness_rows' => [],
            ];
        }
    };
    $audit = new class extends AgenticPlannerApplyExperimentAuditService
    {
        public function __construct() {}

        public function audit(array $filters = []): array
        {
            return [
                'summary' => [
                    'readiness_regression_count' => 0,
                    'metadata_only_ok_count' => 0,
                    'duplicate_risk_count' => 0,
                    'continuity_risk_count' => 0,
                    'lifecycle_risk_count' => 0,
                    'signature_mismatch_count' => 0,
                ],
                'rows' => [],
                'rollback' => [],
            ];
        }
    };

    $report = (new AgenticCanonicalPlannerShadowService($experiment, $audit))->compare($objective);

    expect($report['summary']['priority_order_difference_count'])->toBe(2)
        ->and($report['summary']['exact_order_match_count'])->toBe(0)
        ->and($report['sample_differences'][0]['type'])->toBe('priority_order_difference');
});

it('keeps the shadow command read-only', function (): void {
    [, $workspace, , $objective] = phase3pContext('phase-3p-command');
    $legacy = phase3pOpportunity($objective, ['status' => 'dismissed']);
    $canonical = phase3pCanonical($legacy);
    $legacyUpdatedAt = $legacy->updated_at?->toIso8601String();
    $canonicalUpdatedAt = $canonical->updated_at?->toIso8601String();
    $counts = [
        'actions' => AgenticMarketingAction::query()->count(),
        'runs' => AgenticMarketingRun::query()->count(),
        'run_items' => AgenticMarketingRunItem::query()->count(),
        'audit_logs' => AgenticMarketingAuditLog::query()->count(),
        'canonical' => Opportunity::query()->count(),
    ];

    DB::enableQueryLog();

    $this->artisan('mos:shadow-agentic-planner-candidates', [
        '--workspace' => (string) $workspace->id,
        '--limit' => 5,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Phase 3P Agentic planner canonical shadow diagnostics.')
        ->expectsOutputToContain('inspected objectives: 1')
        ->expectsOutputToContain('shadow canonical candidate count: 1')
        ->expectsOutputToContain('sample legacy order:')
        ->expectsOutputToContain('sample shadow order:')
        ->expectsOutputToContain('sample differences:')
        ->expectsOutputToContain('recommendation: continue shadow');

    $writeQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $query) === 1)
        ->values();

    expect($writeQueries)->toHaveCount(0)
        ->and($legacy->refresh()->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and($canonical->refresh()->updated_at?->toIso8601String())->toBe($canonicalUpdatedAt)
        ->and(AgenticMarketingAction::query()->count())->toBe($counts['actions'])
        ->and(AgenticMarketingRun::query()->count())->toBe($counts['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($counts['run_items'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($counts['audit_logs'])
        ->and(Opportunity::query()->count())->toBe($counts['canonical']);
});

it('runs default-flow shadow diagnostics without changing planner output or ownership', function (): void {
    config(['features.mos_agentic_planner_canonical_shadow' => true]);
    [, , , $objective] = phase3pContext('phase-3p-default-flow');
    $legacy = phase3pOpportunity($objective);
    $canonical = phase3pCanonical($legacy, ['status' => OpportunityStatus::OPEN]);
    $canonicalActionsBefore = $canonical->recommended_actions;

    $summary = app(AgenticMarketingActionPlanner::class)->planForOpportunity($legacy);

    $action = AgenticMarketingAction::query()->firstOrFail();
    $run = AgenticMarketingRun::query()->firstOrFail();
    $diagnostics = app('mos.agentic_planner_canonical_shadow.last_diagnostics');

    expect($summary)->toHaveKeys(['opportunity_id', 'run_id', 'created', 'reused', 'skipped', 'action_ids'])
        ->and($summary)->not->toHaveKey('shadow_diagnostics')
        ->and($summary['created'])->toBe(1)
        ->and($action->opportunity_id)->toBe((string) $legacy->id)
        ->and($action->opportunity_id)->not->toBe((string) $canonical->id)
        ->and($action->payload['planning'])->not->toHaveKey('canonical_planner_experiment')
        ->and($action->payload)->not->toHaveKey('planner_experiment')
        ->and($run->result)->toBe($summary)
        ->and($canonical->refresh()->recommended_actions)->toBe($canonicalActionsBefore)
        ->and($diagnostics['ok'])->toBeTrue();
});

it('reports default-flow shadow errors as diagnostics without blocking planning', function (): void {
    config(['features.mos_agentic_planner_canonical_shadow' => true]);
    [, , , $objective] = phase3pContext('phase-3p-shadow-error');
    $legacy = phase3pOpportunity($objective);

    app()->bind(AgenticCanonicalPlannerShadowService::class, fn (): object => new class extends AgenticCanonicalPlannerShadowService
    {
        public function __construct() {}

        public function compare(AgenticMarketingObjective $objective, array $filters = []): array
        {
            throw new RuntimeException('shadow exploded safely');
        }
    });

    $summary = app(AgenticMarketingActionPlanner::class)->planForOpportunity($legacy);
    $diagnostics = app('mos.agentic_planner_canonical_shadow.last_diagnostics');

    expect($summary['created'])->toBe(1)
        ->and(AgenticMarketingAction::query()->count())->toBe(1)
        ->and($diagnostics['ok'])->toBeFalse()
        ->and($diagnostics['error'])->toBe('shadow exploded safely')
        ->and($diagnostics['objective_id'])->toBe((string) $objective->id);
});
