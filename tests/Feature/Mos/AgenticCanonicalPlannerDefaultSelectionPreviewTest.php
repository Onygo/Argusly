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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerDefaultSelectionPreviewService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerShadowService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerApplyExperimentAuditService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerReadinessInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3qContext(string $slug = 'phase-3q'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3Q '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3Q Workspace',
        'display_name' => 'Phase 3Q Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3Q Site',
        'site_url' => 'https://phase-3q.test',
        'base_url' => 'https://phase-3q.test',
        'allowed_domains' => ['phase-3q.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3Q objective',
        'goal' => 'Preview canonical planner default selection',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3qOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    $token = 'phase-3q-'.Str::lower(Str::random(8));

    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3Q preview',
        'type' => 'content_network',
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Planner preview contract '.$token,
            'reasoning' => 'Default-selection preview should not change planner ownership.',
            'recommendation' => 'Keep legacy planner behaviour.',
            'primary_search_intent' => 'implementation',
            'target_audience' => 'content teams',
            'signals' => [
                'cluster_id' => $token,
                'cluster_name' => 'Planner preview contract '.$token,
                'topic_keyword' => 'Planner preview contract '.$token,
                'gap_type' => 'missing_pillar',
            ],
            'score_explanation' => [
                'summary' => 'Preview comparison is diagnostic only.',
                'impact_score' => 80,
                'confidence_score' => 75,
                'effort_score' => 45,
            ],
        ],
    ], $overrides));
}

function phase3qCanonical(AgenticMarketingOpportunity $legacy, array $overrides = []): Opportunity
{
    $legacy->loadMissing('objective');

    return Opportunity::factory()->create(array_merge([
        'organization_id' => $legacy->objective->organization_id,
        'workspace_id' => $legacy->objective->workspace_id,
        'client_site_id' => $legacy->objective->client_site_id,
        'agentic_marketing_opportunity_id' => $legacy->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::DISMISSED,
        'title' => 'Canonical Phase 3Q planner preview',
        'topic' => 'Planner preview contract',
        'summary' => 'Linked canonical context for preview diagnostics.',
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

function phase3qPreviewReport(array $overrides = []): array
{
    $report = [
        'objective_id' => 'objective-a',
        'workspace_id' => 'workspace-a',
        'site_id' => 'site-a',
        'summary' => [
            'legacy_candidate_count' => 2,
            'shadow_canonical_candidate_count' => 2,
            'exact_order_match_count' => 1,
            'priority_order_difference_count' => 0,
            'blocked_canonical_candidate_count' => 0,
            'readiness_regression_count' => 0,
            'phase_3o_risky_count' => 0,
            'duplicate_risk_count' => 0,
            'continuity_risk_count' => 0,
            'lifecycle_risk_count' => 0,
            'signature_mismatch_count' => 0,
            'shadow_safe_objective_count' => 1,
            'blocked_objective_count' => 0,
            'recommendation' => 'continue shadow',
        ],
        'legacy_order' => [
            ['rank' => 1, 'legacy_opportunity_id' => 'legacy-a', 'priority_score' => 90, 'action_types' => [AgenticMarketingActionType::CreateArticle->value]],
            ['rank' => 2, 'legacy_opportunity_id' => 'legacy-b', 'priority_score' => 80, 'action_types' => [AgenticMarketingActionType::UpdateMeta->value]],
        ],
        'shadow_canonical_order' => [
            ['rank' => 1, 'legacy_opportunity_id' => 'legacy-a', 'canonical_opportunity_id' => 'canonical-a', 'canonical_priority_score' => 90, 'action_types' => [AgenticMarketingActionType::CreateArticle->value]],
            ['rank' => 2, 'legacy_opportunity_id' => 'legacy-b', 'canonical_opportunity_id' => 'canonical-b', 'canonical_priority_score' => 80, 'action_types' => [AgenticMarketingActionType::UpdateMeta->value]],
        ],
        'readiness_rows' => [
            ['legacy_agentic_opportunity_id' => 'legacy-a', 'readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY],
            ['legacy_agentic_opportunity_id' => 'legacy-b', 'readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY],
        ],
        'phase_3o_audit_rows' => [],
        'phase_3o_risky_rows' => [],
        'excluded_rows' => [],
        'priority_order_differences' => [],
        'action_signature_equivalence' => [
            ['legacy_opportunity_id' => 'legacy-a', 'action_type' => AgenticMarketingActionType::CreateArticle->value, 'equivalent' => true],
            ['legacy_opportunity_id' => 'legacy-b', 'action_type' => AgenticMarketingActionType::UpdateMeta->value, 'equivalent' => true],
        ],
        'sample_differences' => [],
    ];

    foreach ($overrides as $key => $value) {
        $report[$key] = is_array($value) && isset($report[$key]) && is_array($report[$key]) && $key === 'summary'
            ? array_replace_recursive($report[$key], $value)
            : $value;
    }

    return $report;
}

function phase3qPreviewService(array $shadowReport): AgenticCanonicalPlannerDefaultSelectionPreviewService
{
    $shadow = new class($shadowReport) extends AgenticCanonicalPlannerShadowService
    {
        public function __construct(private readonly array $report) {}

        public function compare(AgenticMarketingObjective $objective, array $filters = []): array
        {
            return $this->report;
        }
    };

    return new AgenticCanonicalPlannerDefaultSelectionPreviewService($shadow);
}

it('keeps the default-selection preview feature flag off by default', function (): void {
    expect(config('features.mos_agentic_planner_canonical_default_selection_preview'))->toBeFalse();
});

it('requires objective and limit options for the preview command', function (): void {
    $this->artisan('mos:preview-agentic-planner-default-selection', ['--limit' => 5])
        ->assertExitCode(2)
        ->expectsOutputToContain('The --objective option is required.');

    [, , , $objective] = phase3qContext('phase-3q-required-options');

    $this->artisan('mos:preview-agentic-planner-default-selection', ['--objective' => (string) $objective->id])
        ->assertExitCode(2)
        ->expectsOutputToContain('The --limit option is required.');
});

it('returns legacy and canonical proposed default orders separately', function (): void {
    [, , , $objective] = phase3qContext('phase-3q-orders');

    $report = phase3qPreviewService(phase3qPreviewReport())->preview($objective, ['limit' => 10]);

    expect(collect($report['legacy_candidate_order'])->pluck('legacy_opportunity_id')->all())->toBe(['legacy-a', 'legacy-b'])
        ->and(collect($report['canonical_proposed_default_order'])->pluck('canonical_opportunity_id')->all())->toBe(['canonical-a', 'canonical-b'])
        ->and($report['exact_order_match'])->toBeTrue()
        ->and($report['default_selection_preview_status'])->toBe(AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_PREVIEW_SAFE)
        ->and($report['summary']['recommendation'])->toBe('eligible for Phase 3R scoped default experiment');
});

it('blocks preview safety unless Phase 3P recommends continue shadow', function (): void {
    [, , , $objective] = phase3qContext('phase-3q-shadow-regressed');

    $report = phase3qPreviewService(phase3qPreviewReport([
        'summary' => ['recommendation' => 'blocked'],
    ]))->preview($objective, ['limit' => 10]);

    expect($report['default_selection_preview_status'])->toBe(AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_SHADOW_REGRESSED)
        ->and($report['apply_safety']['safe'])->toBeFalse()
        ->and($report['apply_safety']['blocked_reasons'])->toContain('phase_3p_shadow_recommendation_is_not_continue_shadow');
});

it('blocks Phase 3O risky status from preview safety', function (): void {
    [, , , $objective] = phase3qContext('phase-3q-audit-risk');

    $report = phase3qPreviewService(phase3qPreviewReport([
        'phase_3o_risky_rows' => [
            ['action_id' => 'action-a', 'audit_status' => AgenticPlannerApplyExperimentAuditService::STATUS_SIGNATURE_MISMATCH],
        ],
    ]))->preview($objective, ['limit' => 10]);

    expect($report['default_selection_preview_status'])->toBe(AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_AUDIT_RISK)
        ->and($report['summary']['phase_3o_risky_count'])->toBe(1);
});

it('blocks non Phase 3L ready canonical proposed rows', function (): void {
    [, , , $objective] = phase3qContext('phase-3q-readiness-risk');

    $report = phase3qPreviewService(phase3qPreviewReport([
        'readiness_rows' => [
            ['legacy_agentic_opportunity_id' => 'legacy-a', 'readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY],
            ['legacy_agentic_opportunity_id' => 'legacy-b', 'readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_METADATA_READY_ONLY],
        ],
    ]))->preview($objective, ['limit' => 10]);

    expect($report['default_selection_preview_status'])->toBe(AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_PREVIEW_BLOCKED)
        ->and($report['summary']['readiness_regression_count'])->toBe(1);
});

it('blocks signature, continuity, lifecycle and duplicate risks', function (array $summary, string $status): void {
    [, , , $objective] = phase3qContext('phase-3q-risk-'.Str::random(4));

    $report = phase3qPreviewService(phase3qPreviewReport([
        'summary' => $summary,
    ]))->preview($objective, ['limit' => 10]);

    expect($report['default_selection_preview_status'])->toBe($status)
        ->and($report['apply_safety']['safe'])->toBeFalse();
})->with([
    'signature risk' => [['signature_mismatch_count' => 1], AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_SIGNATURE_RISK],
    'continuity risk' => [['continuity_risk_count' => 1], AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_CONTINUITY_RISK],
    'lifecycle risk' => [['lifecycle_risk_count' => 1], AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_LIFECYCLE_RISK],
    'duplicate risk' => [['duplicate_risk_count' => 1], AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_DUPLICATE_RISK],
]);

it('blocks insufficient canonical coverage for the selected legacy scope', function (): void {
    [, , , $objective] = phase3qContext('phase-3q-coverage-risk');

    $report = phase3qPreviewService(phase3qPreviewReport([
        'shadow_canonical_order' => [
            ['rank' => 1, 'legacy_opportunity_id' => 'legacy-a', 'canonical_opportunity_id' => 'canonical-a', 'canonical_priority_score' => 90],
        ],
    ]))->preview($objective, ['limit' => 10]);

    expect($report['default_selection_preview_status'])->toBe(AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_INSUFFICIENT_CANONICAL_COVERAGE)
        ->and(collect($report['legacy_only_candidates'])->pluck('legacy_opportunity_id')->all())->toBe(['legacy-b'])
        ->and($report['summary']['coverage_percentage'])->toBe(50.0);
});

it('allows metadata_only_ok only as traceability, not action ownership approval', function (): void {
    [, , , $objective] = phase3qContext('phase-3q-metadata-only');

    $report = phase3qPreviewService(phase3qPreviewReport([
        'phase_3o_audit_rows' => [
            ['action_id' => 'action-a', 'audit_status' => AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK],
        ],
    ]))->preview($objective, ['limit' => 10]);

    expect($report['default_selection_preview_status'])->toBe(AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_PREVIEW_SAFE)
        ->and($report['apply_safety']['metadata_only_traceability_count'])->toBe(1)
        ->and($report['apply_safety']['metadata_only_action_ownership_approved'])->toBeFalse();
});

it('keeps the preview command read-only', function (): void {
    [, $workspace, , $objective] = phase3qContext('phase-3q-command');
    $legacy = phase3qOpportunity($objective, ['status' => 'dismissed']);
    $canonical = phase3qCanonical($legacy);
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

    $this->artisan('mos:preview-agentic-planner-default-selection', [
        '--workspace' => (string) $workspace->id,
        '--objective' => (string) $objective->id,
        '--limit' => 5,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Phase 3Q Agentic planner default-selection preview.')
        ->expectsOutputToContain('objective id: '.(string) $objective->id)
        ->expectsOutputToContain('sample legacy order:')
        ->expectsOutputToContain('sample canonical proposed order:')
        ->expectsOutputToContain('default selection preview status:');

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

it('runs default-flow preview diagnostics without changing planner output or ownership', function (): void {
    config([
        'features.mos_agentic_planner_canonical_shadow' => true,
        'features.mos_agentic_planner_canonical_default_selection_preview' => true,
    ]);
    [, , , $objective] = phase3qContext('phase-3q-default-flow');
    $legacy = phase3qOpportunity($objective);
    $canonical = phase3qCanonical($legacy, ['status' => OpportunityStatus::OPEN]);
    $canonicalActionsBefore = $canonical->recommended_actions;

    $summary = app(AgenticMarketingActionPlanner::class)->planForOpportunity($legacy);

    $action = AgenticMarketingAction::query()->firstOrFail();
    $diagnostics = app('mos.agentic_planner_canonical_default_selection_preview.last_diagnostics');

    expect($summary)->not->toHaveKey('default_selection_preview')
        ->and($summary['created'])->toBe(1)
        ->and($action->opportunity_id)->toBe((string) $legacy->id)
        ->and($action->opportunity_id)->not->toBe((string) $canonical->id)
        ->and($action->payload['planning'])->not->toHaveKey('canonical_planner_experiment')
        ->and($action->payload)->not->toHaveKey('planner_experiment')
        ->and($canonical->refresh()->recommended_actions)->toBe($canonicalActionsBefore)
        ->and($diagnostics['ok'])->toBeTrue();
});
