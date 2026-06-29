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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerDefaultSelectionExperimentService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerDefaultSelectionPreviewService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityActionSignatureService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerApplyExperimentAuditService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionExperimentAuditService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerReadinessInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3sContext(string $slug = 'phase-3s'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3S '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3S Workspace',
        'display_name' => 'Phase 3S Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3S Site',
        'site_url' => 'https://phase-3s.test',
        'base_url' => 'https://phase-3s.test',
        'allowed_domains' => ['phase-3s.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3S objective',
        'goal' => 'Audit scoped default selection',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3sOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    $token = 'phase-3s-'.Str::lower(Str::random(8));

    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3S default selection',
        'type' => 'content_network',
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Planner default selection '.$token,
            'reasoning' => 'Default-selection experiment should preserve legacy ownership.',
            'recommendation' => 'Use the guarded wrapper.',
            'primary_search_intent' => 'implementation',
            'target_audience' => 'content teams',
            'signals' => [
                'cluster_id' => $token,
                'cluster_name' => 'Planner default selection '.$token,
                'topic_keyword' => 'Planner default selection '.$token,
                'gap_type' => 'missing_pillar',
            ],
            'score_explanation' => [
                'summary' => 'Phase 3S action is legacy owned.',
                'impact_score' => 80,
                'confidence_score' => 75,
                'effort_score' => 45,
            ],
        ],
    ], $overrides));
}

function phase3sCanonical(AgenticMarketingOpportunity $legacy, array $overrides = []): Opportunity
{
    $legacy->loadMissing('objective');

    return Opportunity::factory()->create(array_merge([
        'organization_id' => $legacy->objective->organization_id,
        'workspace_id' => $legacy->objective->workspace_id,
        'client_site_id' => $legacy->objective->client_site_id,
        'agentic_marketing_opportunity_id' => $legacy->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::DISMISSED,
        'title' => 'Canonical Phase 3S default selection',
        'topic' => 'Planner default selection',
        'summary' => 'Linked canonical context for default-selection experiment audit.',
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

function phase3sPreviewReport(AgenticMarketingObjective $objective, AgenticMarketingOpportunity $legacy, Opportunity $canonical, array $overrides = []): array
{
    $report = [
        'objective_id' => (string) $objective->id,
        'workspace_id' => (string) $objective->workspace_id,
        'site_id' => (string) $objective->client_site_id,
        'canonical_proposed_default_order' => [
            [
                'rank' => 1,
                'legacy_opportunity_id' => (string) $legacy->id,
                'canonical_opportunity_id' => (string) $canonical->id,
                'canonical_priority_score' => 70,
                'readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY,
                'action_types' => [AgenticMarketingActionType::CreateArticle->value],
            ],
        ],
        'default_selection_preview_status' => AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_PREVIEW_SAFE,
        'apply_safety' => [
            'phase_3p_recommendation' => 'continue shadow',
        ],
        'phase_3p_shadow_recommendation' => 'continue shadow',
        'phase_3o_audit_rows' => [
            [
                'legacy_opportunity_id' => (string) $legacy->id,
                'audit_status' => AgenticPlannerApplyExperimentAuditService::STATUS_CLEAN,
            ],
        ],
        'phase_3o_risky_rows' => [],
    ];

    foreach ($overrides as $key => $value) {
        $report[$key] = is_array($value) && isset($report[$key]) && is_array($report[$key])
            ? array_replace_recursive($report[$key], $value)
            : $value;
    }

    return $report;
}

function phase3sBindPreview(array $report): void
{
    app()->instance(AgenticCanonicalPlannerDefaultSelectionPreviewService::class, new class($report) extends AgenticCanonicalPlannerDefaultSelectionPreviewService
    {
        public function __construct(private readonly array $report) {}

        public function preview(AgenticMarketingObjective $objective, array $filters = []): array
        {
            return $this->report;
        }
    });
}

function phase3sBindReadiness(array $report): void
{
    app()->instance(AgenticPlannerReadinessInspectionService::class, new class($report) extends AgenticPlannerReadinessInspectionService
    {
        public function __construct(private readonly array $report) {}

        public function inspect(AgenticMarketingOpportunity $opportunity): array
        {
            return $this->report;
        }
    });
}

function phase3sBindSafeReadiness(): void
{
    phase3sBindReadiness([
        'readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY,
        'readiness_blocked_reasons' => [],
        'phase_3i_continuity_status' => ['canonical_parent_only_lookup_blockers' => []],
        'phase_3j_lifecycle_action_ownership_status' => ['status_conflict' => false, 'lifecycle_status_ambiguous' => false],
        'duplicate_action_risk' => ['items' => []],
    ]);
}

function phase3sAction(AgenticMarketingObjective $objective, AgenticMarketingOpportunity $legacy, Opportunity $canonical, array $metadataOverrides = [], array $actionOverrides = []): AgenticMarketingAction
{
    $signature = app(AgenticOpportunityActionSignatureService::class)
        ->forCanonicalActionCandidate($canonical, AgenticMarketingActionType::CreateArticle->value)['signature'];

    $metadata = array_replace([
        'version' => AgenticCanonicalPlannerDefaultSelectionExperimentService::METADATA_VERSION,
        'canonical_opportunity_id' => (string) $canonical->id,
        'legacy_agentic_marketing_opportunity_id' => (string) $legacy->id,
        'objective_id' => (string) $objective->id,
        'workspace_id' => (string) $objective->workspace_id,
        'selection_source' => 'canonical_default_selection_experiment',
        'phase_3q_preview_status' => 'preview_safe',
        'phase_3p_recommendation' => 'continue_shadow',
        'phase_3m_signature' => $signature,
        'phase_3l_readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY,
        'applied_at' => now()->toIso8601String(),
        'applied_by' => 'command',
    ], $metadataOverrides);

    return AgenticMarketingAction::query()->create(array_replace_recursive([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'title' => 'Phase 3S action',
            'default_selection_experiment' => $metadata,
        ],
    ], $actionOverrides));
}

it('keeps the Phase 3S audit command read-only', function (): void {
    [, , , $objective] = phase3sContext('phase-3s-audit-read-only');
    $legacy = phase3sOpportunity($objective);
    $canonical = phase3sCanonical($legacy);
    $action = phase3sAction($objective, $legacy, $canonical);
    phase3sBindPreview(phase3sPreviewReport($objective, $legacy, $canonical));

    $before = [
        'action' => $action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']),
        'runs' => AgenticMarketingRun::query()->count(),
        'run_items' => AgenticMarketingRunItem::query()->count(),
        'audit_logs' => AgenticMarketingAuditLog::query()->count(),
    ];

    $this->artisan('mos:audit-agentic-planner-default-selection-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 1,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Phase 3S Agentic planner default-selection experiment audit.')
        ->expectsOutputToContain('inspected action count: 1');

    expect($action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']))->toBe($before['action'])
        ->and(AgenticMarketingRun::query()->count())->toBe($before['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($before['run_items'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($before['audit_logs']);
});

it('reports clean and metadata-only-ok Phase 3R actions', function (string $phase3oStatus, string $expected): void {
    [, , , $objective] = phase3sContext('phase-3s-'.$expected);
    $legacy = phase3sOpportunity($objective);
    $canonical = phase3sCanonical($legacy);
    phase3sAction($objective, $legacy, $canonical);
    phase3sBindSafeReadiness();
    phase3sBindPreview(phase3sPreviewReport($objective, $legacy, $canonical, [
        'phase_3o_audit_rows' => [['legacy_opportunity_id' => (string) $legacy->id, 'audit_status' => $phase3oStatus]],
    ]));

    $report = app(AgenticPlannerDefaultSelectionExperimentAuditService::class)->audit(['objective' => (string) $objective->id]);

    expect($report['rows'][0]['audit_status'])->toBe($expected);
})->with([
    'clean' => [AgenticPlannerApplyExperimentAuditService::STATUS_CLEAN, AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_CLEAN],
    'metadata only ok' => [AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK, AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_METADATA_ONLY_OK],
]);

it('reports Phase 3S risk statuses', function (Closure $mutate, string $expected): void {
    [, , , $objective] = phase3sContext('phase-3s-'.$expected);
    $legacy = phase3sOpportunity($objective);
    $canonical = phase3sCanonical($legacy);
    $action = phase3sAction($objective, $legacy, $canonical);
    phase3sBindSafeReadiness();
    phase3sBindPreview(phase3sPreviewReport($objective, $legacy, $canonical));

    $mutate($objective, $legacy, $canonical, $action);

    $report = app(AgenticPlannerDefaultSelectionExperimentAuditService::class)->audit(['objective' => (string) $objective->id]);

    expect($report['rows'][0]['audit_status'])->toBe($expected);
})->with([
    'missing legacy parent' => [
        function (AgenticMarketingObjective $objective, AgenticMarketingOpportunity $legacy, Opportunity $canonical, AgenticMarketingAction $action): void {
            DB::table($action->getTable())->where('id', $action->id)->update(['opportunity_id' => null]);
        },
        AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_MISSING_LEGACY_PARENT,
    ],
    'missing canonical context' => [
        function (AgenticMarketingObjective $objective, AgenticMarketingOpportunity $legacy, Opportunity $canonical): void {
            $canonical->delete();
        },
        AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_MISSING_CANONICAL_CONTEXT,
    ],
    'bridge mismatch' => [
        function (AgenticMarketingObjective $objective, AgenticMarketingOpportunity $legacy, Opportunity $canonical): void {
            $other = phase3sOpportunity($objective);
            $canonical->update(['agentic_marketing_opportunity_id' => $other->id]);
        },
        AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_BRIDGE_MISMATCH,
    ],
    'preview regression' => [
        function (AgenticMarketingObjective $objective, AgenticMarketingOpportunity $legacy, Opportunity $canonical): void {
            phase3sBindPreview(phase3sPreviewReport($objective, $legacy, $canonical, [
                'default_selection_preview_status' => AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_KEEP_LEGACY,
            ]));
        },
        AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_PREVIEW_REGRESSED,
    ],
    'shadow regression' => [
        function (AgenticMarketingObjective $objective, AgenticMarketingOpportunity $legacy, Opportunity $canonical): void {
            phase3sBindPreview(phase3sPreviewReport($objective, $legacy, $canonical, [
                'phase_3p_shadow_recommendation' => 'keep legacy',
                'apply_safety' => ['phase_3p_recommendation' => 'keep legacy'],
            ]));
        },
        AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_SHADOW_REGRESSED,
    ],
    'Phase 3O risk' => [
        function (AgenticMarketingObjective $objective, AgenticMarketingOpportunity $legacy, Opportunity $canonical): void {
            phase3sBindPreview(phase3sPreviewReport($objective, $legacy, $canonical, [
                'phase_3o_audit_rows' => [['legacy_opportunity_id' => (string) $legacy->id, 'audit_status' => AgenticPlannerApplyExperimentAuditService::STATUS_SIGNATURE_MISMATCH]],
                'phase_3o_risky_rows' => [['legacy_opportunity_id' => (string) $legacy->id, 'audit_status' => AgenticPlannerApplyExperimentAuditService::STATUS_SIGNATURE_MISMATCH]],
            ]));
        },
        AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_PHASE_3O_AUDIT_RISK,
    ],
    'readiness regression' => [
        function (): void {
            phase3sBindReadiness([
                'readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_BLOCKED,
                'readiness_blocked_reasons' => ['missing_candidate_action_type'],
                'phase_3i_continuity_status' => ['canonical_parent_only_lookup_blockers' => []],
                'phase_3j_lifecycle_action_ownership_status' => ['status_conflict' => false, 'lifecycle_status_ambiguous' => false],
                'duplicate_action_risk' => ['items' => []],
            ]);
        },
        AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_READINESS_REGRESSED,
    ],
    'signature mismatch' => [
        function (AgenticMarketingObjective $objective, AgenticMarketingOpportunity $legacy, Opportunity $canonical, AgenticMarketingAction $action): void {
            $payload = $action->payload;
            $payload['default_selection_experiment']['phase_3m_signature'] = hash('sha256', 'not-current');
            DB::table($action->getTable())->where('id', $action->id)->update(['payload' => json_encode($payload, JSON_UNESCAPED_SLASHES)]);
        },
        AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_SIGNATURE_MISMATCH,
    ],
    'continuity risk' => [
        function (): void {
            phase3sBindReadiness([
                'readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY,
                'readiness_blocked_reasons' => [],
                'phase_3i_continuity_status' => ['canonical_parent_only_lookup_blockers' => ['canonical_parent_only_lookup_would_miss_assets']],
                'phase_3j_lifecycle_action_ownership_status' => ['status_conflict' => false, 'lifecycle_status_ambiguous' => false],
                'duplicate_action_risk' => ['items' => []],
            ]);
        },
        AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_CONTINUITY_RISK,
    ],
    'lifecycle risk' => [
        function (): void {
            phase3sBindReadiness([
                'readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY,
                'readiness_blocked_reasons' => [],
                'phase_3i_continuity_status' => ['canonical_parent_only_lookup_blockers' => []],
                'phase_3j_lifecycle_action_ownership_status' => ['status_conflict' => true, 'lifecycle_status_ambiguous' => false],
                'duplicate_action_risk' => ['items' => []],
            ]);
        },
        AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_LIFECYCLE_RISK,
    ],
    'duplicate risk' => [
        function (AgenticMarketingObjective $objective, AgenticMarketingOpportunity $legacy, Opportunity $canonical, AgenticMarketingAction $action): void {
            $duplicate = phase3sAction($objective, $legacy, $canonical, [], [
                'payload' => ['title' => 'Different payload for duplicate mutation'],
            ]);
            DB::table($duplicate->getTable())->where('id', $duplicate->id)->update(['dedupe_hash' => $action->dedupe_hash]);
        },
        AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_DUPLICATE_RISK,
    ],
    'ownership risk' => [
        function (AgenticMarketingObjective $objective, AgenticMarketingOpportunity $legacy, Opportunity $canonical, AgenticMarketingAction $action): void {
            $other = phase3sOpportunity($objective);
            DB::table($action->getTable())->where('id', $action->id)->update(['opportunity_id' => (string) $other->id]);
        },
        AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_OWNERSHIP_RISK,
    ],
]);

it('keeps the Phase 3S rollback plan command read-only and does not add a metadata removal writer', function (): void {
    [, , , $objective] = phase3sContext('phase-3s-rollback-read-only');
    $legacy = phase3sOpportunity($objective);
    $canonical = phase3sCanonical($legacy);
    $action = phase3sAction($objective, $legacy, $canonical);
    phase3sBindPreview(phase3sPreviewReport($objective, $legacy, $canonical));
    $before = $action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']);

    $this->artisan('mos:plan-agentic-planner-default-selection-experiment-rollback', [
        '--objective' => (string) $objective->id,
        '--limit' => 1,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('metadata path: payload.default_selection_experiment')
        ->expectsOutputToContain('No metadata is removed.');

    expect($action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']))->toBe($before)
        ->and(Artisan::all())->not->toHaveKey('mos:remove-agentic-planner-default-selection-experiment-metadata');
});

it('leaves default AgenticMarketingActionPlanner behavior unchanged', function (): void {
    [, , , $objective] = phase3sContext('phase-3s-default-planner');
    $legacy = phase3sOpportunity($objective);
    $canonical = phase3sCanonical($legacy);
    $canonicalRecommendedActions = $canonical->recommended_actions;

    $summary = app(AgenticMarketingActionPlanner::class)->planForOpportunity($legacy);
    $action = AgenticMarketingAction::query()->firstOrFail();

    expect($summary['created'])->toBe(1)
        ->and($action->opportunity_id)->toBe((string) $legacy->id)
        ->and($action->payload)->not->toHaveKey('default_selection_experiment')
        ->and($canonical->refresh()->recommended_actions)->toBe($canonicalRecommendedActions);
});
