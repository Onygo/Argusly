<?php

use App\Enums\AgenticMarketingActionType;
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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerApplyExperimentAuditService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionExperimentAuditService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionRolloutReadinessService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerReadinessInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3tContext(string $slug = 'phase-3t'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3T '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3T Workspace',
        'display_name' => 'Phase 3T Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3T Site',
        'site_url' => 'https://phase-3t.test',
        'base_url' => 'https://phase-3t.test',
        'allowed_domains' => ['phase-3t.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3T objective',
        'goal' => 'Inspect default-selection rollout readiness',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3tObjective(Workspace $workspace, ClientSite $site, Organization $organization): AgenticMarketingObjective
{
    return AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3T objective '.Str::random(4),
        'goal' => 'Inspect default-selection rollout readiness',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);
}

function phase3tOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3T rollout readiness',
        'type' => 'content_network',
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Phase 3T '.Str::lower(Str::random(8)),
            'reasoning' => 'Rollout readiness is diagnostic only.',
            'recommendation' => 'Keep legacy ownership.',
            'signals' => ['topic_keyword' => 'Phase 3T'],
        ],
    ], $overrides));
}

function phase3tPreviewReport(AgenticMarketingObjective $objective, array $overrides = []): array
{
    $legacyId = 'legacy-'.$objective->id;
    $canonicalId = 'canonical-'.$objective->id;
    $report = [
        'objective_id' => (string) $objective->id,
        'workspace_id' => (string) $objective->workspace_id,
        'site_id' => (string) $objective->client_site_id,
        'legacy_candidate_order' => [
            ['rank' => 1, 'legacy_opportunity_id' => $legacyId, 'priority_score' => 70, 'action_types' => [AgenticMarketingActionType::CreateArticle->value]],
        ],
        'canonical_proposed_default_order' => [
            ['rank' => 1, 'legacy_opportunity_id' => $legacyId, 'canonical_opportunity_id' => $canonicalId, 'canonical_priority_score' => 70, 'action_types' => [AgenticMarketingActionType::CreateArticle->value]],
        ],
        'exact_order_match' => true,
        'default_selection_preview_status' => AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_PREVIEW_SAFE,
        'apply_safety' => [
            'phase_3p_recommendation' => 'continue shadow',
            'phase_3p_continue_shadow' => true,
            'phase_3o_risky_count' => 0,
            'phase_3l_canonical_readiness_regression_count' => 0,
            'phase_3h_signature_risk_count' => 0,
            'phase_3i_continuity_risk_count' => 0,
            'phase_3j_lifecycle_risk_count' => 0,
            'duplicate_open_action_risk_count' => 0,
            'canonical_coverage_sufficient' => true,
            'exact_order_match' => true,
            'metadata_only_action_ownership_approved' => false,
            'blocked_reasons' => [],
        ],
        'summary' => [
            'legacy_candidate_count' => 1,
            'canonical_proposed_count' => 1,
            'readiness_regression_count' => 0,
            'signature_risk_count' => 0,
            'continuity_risk_count' => 0,
            'lifecycle_risk_count' => 0,
            'duplicate_risk_count' => 0,
        ],
        'phase_3p_shadow_recommendation' => 'continue shadow',
        'phase_3o_audit_rows' => [['audit_status' => AgenticPlannerApplyExperimentAuditService::STATUS_CLEAN]],
        'phase_3o_risky_rows' => [],
        'phase_3l_readiness_rows' => [
            ['legacy_agentic_opportunity_id' => $legacyId, 'linked_canonical_opportunity_id' => $canonicalId, 'readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY],
        ],
        'phase_3h_signature_equivalence' => [
            ['legacy_opportunity_id' => $legacyId, 'action_type' => AgenticMarketingActionType::CreateArticle->value, 'equivalent' => true],
        ],
    ];

    foreach ($overrides as $key => $value) {
        $report[$key] = is_array($value) && isset($report[$key]) && is_array($report[$key])
            ? array_replace_recursive($report[$key], $value)
            : $value;
    }

    return $report;
}

function phase3tService(array $previewReports, array $phase3sRows = [], array $phase3oRows = []): AgenticPlannerDefaultSelectionRolloutReadinessService
{
    $preview = new class($previewReports) extends AgenticCanonicalPlannerDefaultSelectionPreviewService
    {
        public function __construct(private readonly array $reports) {}

        public function preview(AgenticMarketingObjective $objective, array $filters = []): array
        {
            return $this->reports[(string) $objective->id] ?? $this->reports[0];
        }
    };
    $phase3s = new class($phase3sRows) extends AgenticPlannerDefaultSelectionExperimentAuditService
    {
        public function __construct(private readonly array $rowsByObjective) {}

        public function audit(array $filters = []): array
        {
            $rows = $this->rowsByObjective[(string) ($filters['objective'] ?? '')] ?? $this->rowsByObjective;

            return ['summary' => [], 'rows' => array_values($rows), 'rollback' => []];
        }
    };
    $phase3o = new class($phase3oRows) extends AgenticPlannerApplyExperimentAuditService
    {
        public function __construct(private readonly array $rowsByObjective) {}

        public function audit(array $filters = []): array
        {
            $rows = $this->rowsByObjective[(string) ($filters['objective'] ?? '')] ?? $this->rowsByObjective;

            return ['summary' => [], 'rows' => array_values($rows), 'rollback' => []];
        }
    };

    return new AgenticPlannerDefaultSelectionRolloutReadinessService($preview, $phase3s, $phase3o);
}

it('requires workspace and limit options for the Phase 3T command', function (): void {
    $this->artisan('mos:inspect-agentic-planner-default-selection-rollout-readiness', [
        '--objectives' => 'objective-a',
        '--limit' => 5,
    ])
        ->assertExitCode(2)
        ->expectsOutputToContain('The --workspace option is required.');

    [, $workspace, , $objective] = phase3tContext('phase-3t-required-options');

    $this->artisan('mos:inspect-agentic-planner-default-selection-rollout-readiness', [
        '--workspace' => (string) $workspace->id,
        '--objectives' => (string) $objective->id,
    ])
        ->assertExitCode(2)
        ->expectsOutputToContain('The --limit option is required.');
});

it('keeps the Phase 3T command read-only', function (): void {
    [$organization, $workspace, $site, $objective] = phase3tContext('phase-3t-read-only');
    $legacy = phase3tOpportunity($objective);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => ['title' => 'Phase 3T read only'],
    ]);
    $before = [
        'action' => $action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']),
        'actions' => AgenticMarketingAction::query()->count(),
        'runs' => AgenticMarketingRun::query()->count(),
        'run_items' => AgenticMarketingRunItem::query()->count(),
        'audit_logs' => AgenticMarketingAuditLog::query()->count(),
        'canonical' => Opportunity::query()->count(),
    ];
    app()->instance(AgenticPlannerDefaultSelectionRolloutReadinessService::class, phase3tService([
        (string) $objective->id => phase3tPreviewReport($objective),
    ]));

    $this->artisan('mos:inspect-agentic-planner-default-selection-rollout-readiness', [
        '--workspace' => (string) $workspace->id,
        '--objectives' => (string) $objective->id,
        '--limit' => 1,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Phase 3T Agentic planner default-selection rollout readiness.');

    expect($action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']))->toBe($before['action'])
        ->and(AgenticMarketingAction::query()->count())->toBe($before['actions'])
        ->and(AgenticMarketingRun::query()->count())->toBe($before['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($before['run_items'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($before['audit_logs'])
        ->and(Opportunity::query()->count())->toBe($before['canonical']);
});

it('returns ready_for_scoped_expansion only when every objective gate passes', function (): void {
    [$organization, $workspace, $site, $objectiveA] = phase3tContext('phase-3t-ready');
    $objectiveB = phase3tObjective($workspace, $site, $organization);

    $report = phase3tService([
        (string) $objectiveA->id => phase3tPreviewReport($objectiveA),
        (string) $objectiveB->id => phase3tPreviewReport($objectiveB),
    ])->inspect([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objectiveA->id, (string) $objectiveB->id],
        'limit' => 1,
    ]);

    expect($report['rollout_readiness_status'])->toBe(AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION)
        ->and($report['recommendation'])->toBe('eligible for limited multi-objective Phase 3U')
        ->and($report['summary']['ready_objective_count'])->toBe(2);
});

it('blocks rollout readiness for each required gate', function (Closure $mutate, array $phase3sRows, array $phase3oRows, string $expected): void {
    [, $workspace, , $objective] = phase3tContext('phase-3t-'.$expected);
    $preview = phase3tPreviewReport($objective);
    $mutate($preview);

    $report = phase3tService([
        (string) $objective->id => $preview,
    ], [
        (string) $objective->id => $phase3sRows,
    ], [
        (string) $objective->id => $phase3oRows,
    ])->inspect([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'limit' => 1,
    ]);

    expect($report['rollout_readiness_status'])->toBe($expected)
        ->and($report['summary']['blocked_objective_count'])->toBe($expected === AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_KEEP_SINGLE_OBJECTIVE_SCOPE ? 0 : 1);
})->with([
    'Phase 3S risky row' => [
        fn (array &$preview): null => null,
        [['audit_status' => AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_PREVIEW_REGRESSED, 'action_id' => 'action-a']],
        [],
        AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_PHASE_3S,
    ],
    'Phase 3Q not preview safe' => [
        function (array &$preview): void {
            $preview['default_selection_preview_status'] = AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_SHADOW_REGRESSED;
        },
        [],
        [],
        AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_PREVIEW,
    ],
    'Phase 3Q missing required apply safety diagnostics' => [
        function (array &$preview): void {
            unset($preview['apply_safety']['phase_3i_continuity_risk_count']);
        },
        [],
        [],
        AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_PREVIEW,
    ],
    'Phase 3P not continue shadow' => [
        function (array &$preview): void {
            $preview['phase_3p_shadow_recommendation'] = 'blocked';
            $preview['apply_safety']['phase_3p_recommendation'] = 'blocked';
        },
        [],
        [],
        AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_SHADOW,
    ],
    'Phase 3O risky row' => [
        fn (array &$preview): null => null,
        [],
        [['audit_status' => AgenticPlannerApplyExperimentAuditService::STATUS_SIGNATURE_MISMATCH, 'action_id' => 'action-n']],
        AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_PHASE_3O,
    ],
    'Phase 3L not ready' => [
        function (array &$preview): void {
            $preview['phase_3l_readiness_rows'][0]['readiness_status'] = AgenticPlannerReadinessInspectionService::STATUS_METADATA_READY_ONLY;
        },
        [],
        [],
        AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_READINESS,
    ],
    'Phase 3H signature mismatch' => [
        function (array &$preview): void {
            $preview['phase_3h_signature_equivalence'][0]['equivalent'] = false;
        },
        [],
        [],
        AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_SIGNATURE,
    ],
    'Phase 3I continuity risk' => [
        function (array &$preview): void {
            $preview['apply_safety']['phase_3i_continuity_risk_count'] = 1;
        },
        [],
        [],
        AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_CONTINUITY,
    ],
    'Phase 3J lifecycle risk' => [
        function (array &$preview): void {
            $preview['apply_safety']['phase_3j_lifecycle_risk_count'] = 1;
        },
        [],
        [],
        AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_LIFECYCLE,
    ],
    'duplicate open action risk' => [
        function (array &$preview): void {
            $preview['apply_safety']['duplicate_open_action_risk_count'] = 1;
        },
        [],
        [],
        AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_DUPLICATE_RISK,
    ],
    'insufficient canonical coverage' => [
        function (array &$preview): void {
            $preview['apply_safety']['canonical_coverage_sufficient'] = false;
        },
        [],
        [],
        AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_INSUFFICIENT_CANONICAL_COVERAGE,
    ],
    'canonical order mismatch' => [
        function (array &$preview): void {
            $preview['apply_safety']['exact_order_match'] = false;
        },
        [],
        [],
        AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_ORDER_MISMATCH,
    ],
]);

it('reports metadata_only_ok separately and does not approve canonical ownership', function (): void {
    [, $workspace, , $objective] = phase3tContext('phase-3t-metadata-only');

    $report = phase3tService([
        (string) $objective->id => phase3tPreviewReport($objective),
    ], [
        (string) $objective->id => [['audit_status' => AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_METADATA_ONLY_OK, 'action_id' => 'action-r']],
    ], [
        (string) $objective->id => [['audit_status' => AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK, 'action_id' => 'action-n']],
    ])->inspect([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'limit' => 1,
    ]);

    expect($report['rollout_readiness_status'])->toBe(AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION)
        ->and($report['summary']['metadata_only_ok_count'])->toBe(2)
        ->and($report['objective_rows'][0]['metadata_only_action_ownership_approved'])->toBeFalse();
});

it('fails closed when a required Phase 3Q apply safety diagnostic is missing', function (): void {
    [, $workspace, , $objective] = phase3tContext('phase-3t-missing-preview-diagnostics');
    $preview = phase3tPreviewReport($objective);
    unset($preview['apply_safety']['phase_3j_lifecycle_risk_count']);

    $report = phase3tService([
        (string) $objective->id => $preview,
    ])->inspect([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'limit' => 1,
    ]);

    expect($report['rollout_readiness_status'])->toBe(AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_PREVIEW)
        ->and($report['objective_rows'][0]['missing_preview_diagnostic_count'])->toBe(1)
        ->and($report['objective_rows'][0]['blocked_reasons'])->toContain('phase_3q_apply_safety_missing_phase_3j_lifecycle_risk_count')
        ->and($report['recommendation'])->toBe('blocked');
});

it('does not create canonical recommended actions, Agentic actions, or change default planner behaviour', function (): void {
    [, $workspace, , $objective] = phase3tContext('phase-3t-no-writes');
    app()->instance(AgenticPlannerDefaultSelectionRolloutReadinessService::class, phase3tService([
        (string) $objective->id => phase3tPreviewReport($objective),
    ]));
    $planner = app(AgenticMarketingActionPlanner::class);
    $before = [
        'actions' => AgenticMarketingAction::query()->count(),
        'canonical' => Opportunity::query()->count(),
    ];

    $this->artisan('mos:inspect-agentic-planner-default-selection-rollout-readiness', [
        '--workspace' => (string) $workspace->id,
        '--objectives' => (string) $objective->id,
        '--limit' => 1,
    ])->assertSuccessful();

    expect(AgenticMarketingAction::query()->count())->toBe($before['actions'])
        ->and(Opportunity::query()->count())->toBe($before['canonical'])
        ->and(app(AgenticMarketingActionPlanner::class)::class)->toBe($planner::class);
});
