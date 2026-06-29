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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerApplyExperimentAuditService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerReadinessInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3rContext(string $slug = 'phase-3r'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3R '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3R Workspace',
        'display_name' => 'Phase 3R Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3R Site',
        'site_url' => 'https://phase-3r.test',
        'base_url' => 'https://phase-3r.test',
        'allowed_domains' => ['phase-3r.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3R objective',
        'goal' => 'Apply scoped default selection',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3rOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    $token = 'phase-3r-'.Str::lower(Str::random(8));

    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3R default selection',
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
                'summary' => 'Phase 3R action is created through the legacy planner.',
                'impact_score' => 80,
                'confidence_score' => 75,
                'effort_score' => 45,
            ],
        ],
    ], $overrides));
}

function phase3rCanonical(AgenticMarketingOpportunity $legacy, array $overrides = []): Opportunity
{
    $legacy->loadMissing('objective');

    return Opportunity::factory()->create(array_merge([
        'organization_id' => $legacy->objective->organization_id,
        'workspace_id' => $legacy->objective->workspace_id,
        'client_site_id' => $legacy->objective->client_site_id,
        'agentic_marketing_opportunity_id' => $legacy->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::DISMISSED,
        'title' => 'Canonical Phase 3R default selection',
        'topic' => 'Planner default selection',
        'summary' => 'Linked canonical context for default-selection experiment.',
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

function phase3rPreviewReport(AgenticMarketingObjective $objective, AgenticMarketingOpportunity $legacy, Opportunity $canonical, array $overrides = []): array
{
    $report = [
        'objective_id' => (string) $objective->id,
        'workspace_id' => (string) $objective->workspace_id,
        'site_id' => (string) $objective->client_site_id,
        'legacy_candidate_order' => [
            ['rank' => 1, 'legacy_opportunity_id' => (string) $legacy->id, 'priority_score' => 70, 'action_types' => [AgenticMarketingActionType::CreateArticle->value]],
        ],
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
        'exact_order_match' => true,
        'order_differences' => [],
        'canonical_only_candidates' => [],
        'legacy_only_candidates' => [],
        'blocked_candidates' => [],
        'excluded_reasons' => [],
        'apply_safety' => [
            'safe' => true,
            'phase_3p_recommendation' => 'continue shadow',
            'phase_3p_continue_shadow' => true,
            'phase_3o_risky_count' => 0,
            'phase_3l_canonical_readiness_regression_count' => 0,
            'phase_3h_signature_risk_count' => 0,
            'phase_3i_continuity_risk_count' => 0,
            'phase_3j_lifecycle_risk_count' => 0,
            'duplicate_open_action_risk_count' => 0,
            'legacy_candidate_count' => 1,
            'canonical_proposed_count' => 1,
            'legacy_only_count' => 0,
            'canonical_only_count' => 0,
            'exact_order_match' => true,
            'canonical_coverage_sufficient' => true,
            'blocked_reasons' => [],
        ],
        'default_selection_preview_status' => AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_PREVIEW_SAFE,
        'summary' => [
            'legacy_candidate_count' => 1,
            'canonical_proposed_count' => 1,
            'coverage_percentage' => 100.0,
            'duplicate_risk_count' => 0,
            'continuity_risk_count' => 0,
            'lifecycle_risk_count' => 0,
            'signature_risk_count' => 0,
            'recommendation' => 'eligible for Phase 3R scoped default experiment',
        ],
        'phase_3p_shadow_recommendation' => 'continue shadow',
        'phase_3o_audit_rows' => [
            ['audit_status' => AgenticPlannerApplyExperimentAuditService::STATUS_CLEAN],
        ],
        'phase_3o_risky_rows' => [],
        'phase_3l_readiness_rows' => [
            [
                'legacy_agentic_opportunity_id' => (string) $legacy->id,
                'linked_canonical_opportunity_id' => (string) $canonical->id,
                'workspace_id' => (string) $objective->workspace_id,
                'readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY,
            ],
        ],
        'phase_3h_signature_equivalence' => [
            [
                'legacy_opportunity_id' => (string) $legacy->id,
                'action_type' => AgenticMarketingActionType::CreateArticle->value,
                'canonical_signature' => hash('sha256', 'phase-3r-signature'),
                'equivalent' => true,
                'blocked_reasons' => [],
            ],
        ],
        'phase_3p_shadow_report' => [],
    ];

    foreach ($overrides as $key => $value) {
        $report[$key] = is_array($value) && isset($report[$key]) && is_array($report[$key])
            ? array_replace_recursive($report[$key], $value)
            : $value;
    }

    return $report;
}

function phase3rBindPreview(array $report): void
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

it('keeps the Phase 3R default-selection experiment feature flag off by default', function (): void {
    expect(config('features.mos_agentic_planner_canonical_default_selection_experiment'))->toBeFalse();
});

it('requires objective and limit options for the Phase 3R command', function (): void {
    $this->artisan('mos:apply-agentic-planner-default-selection-experiment', ['--limit' => 5])
        ->assertExitCode(2)
        ->expectsOutputToContain('The --objective option is required.');

    [, , , $objective] = phase3rContext('phase-3r-required-options');

    $this->artisan('mos:apply-agentic-planner-default-selection-experiment', ['--objective' => (string) $objective->id])
        ->assertExitCode(2)
        ->expectsOutputToContain('The --limit option is required.');
});

it('keeps Phase 3R dry-run read-only and reports selected and resolved rows', function (): void {
    config(['features.mos_agentic_planner_canonical_default_selection_experiment' => true]);
    [, , , $objective] = phase3rContext('phase-3r-dry-run');
    $legacy = phase3rOpportunity($objective);
    $canonical = phase3rCanonical($legacy);
    phase3rBindPreview(phase3rPreviewReport($objective, $legacy, $canonical));
    $counts = [
        'actions' => AgenticMarketingAction::query()->count(),
        'runs' => AgenticMarketingRun::query()->count(),
        'run_items' => AgenticMarketingRunItem::query()->count(),
        'audit_logs' => AgenticMarketingAuditLog::query()->count(),
    ];

    $this->artisan('mos:apply-agentic-planner-default-selection-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 1,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Dry run only for Phase 3R Agentic planner default-selection experiment.')
        ->expectsOutputToContain('canonical selected count: 1')
        ->expectsOutputToContain('would create action count: 1')
        ->expectsOutputToContain('selected canonical opportunity ids: '.$canonical->id)
        ->expectsOutputToContain('resolved legacy Agentic opportunity ids: '.$legacy->id);

    expect(AgenticMarketingAction::query()->count())->toBe($counts['actions'])
        ->and(AgenticMarketingRun::query()->count())->toBe($counts['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($counts['run_items'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($counts['audit_logs']);
});

it('blocks Phase 3R apply when the feature flag is disabled', function (): void {
    [, , , $objective] = phase3rContext('phase-3r-flag-blocked');
    $legacy = phase3rOpportunity($objective);
    $canonical = phase3rCanonical($legacy);
    phase3rBindPreview(phase3rPreviewReport($objective, $legacy, $canonical));

    $this->artisan('mos:apply-agentic-planner-default-selection-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 1,
        '--apply' => true,
    ])
        ->assertFailed()
        ->expectsOutputToContain('Apply blocked: enable ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_DEFAULT_SELECTION_EXPERIMENT');

    expect(AgenticMarketingAction::query()->count())->toBe(0);
});

it('blocks Phase 3R apply unless Phase 3Q is preview_safe', function (): void {
    config(['features.mos_agentic_planner_canonical_default_selection_experiment' => true]);
    [, , , $objective] = phase3rContext('phase-3r-preview-blocked');
    $legacy = phase3rOpportunity($objective);
    $canonical = phase3rCanonical($legacy);
    phase3rBindPreview(phase3rPreviewReport($objective, $legacy, $canonical, [
        'default_selection_preview_status' => AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_SHADOW_REGRESSED,
    ]));

    $this->artisan('mos:apply-agentic-planner-default-selection-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 1,
        '--apply' => true,
    ])
        ->assertFailed()
        ->expectsOutputToContain('phase_3q_preview_status_is_not_preview_safe');

    expect(AgenticMarketingAction::query()->count())->toBe(0);
});

it('blocks Phase 3O risky rows, Phase 3L regressions, Phase 3H mismatches, Phase 3I, Phase 3J, duplicate, coverage and order risks', function (array $override, string $reason): void {
    config(['features.mos_agentic_planner_canonical_default_selection_experiment' => true]);
    [, , , $objective] = phase3rContext('phase-3r-'.$reason);
    $legacy = phase3rOpportunity($objective);
    $canonical = phase3rCanonical($legacy);
    phase3rBindPreview(phase3rPreviewReport($objective, $legacy, $canonical, $override));

    $this->artisan('mos:apply-agentic-planner-default-selection-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 1,
        '--apply' => true,
    ])
        ->assertFailed()
        ->expectsOutputToContain($reason);

    expect(AgenticMarketingAction::query()->count())->toBe(0);
})->with([
    'phase 3o' => [[
        'phase_3o_audit_rows' => [['audit_status' => AgenticPlannerApplyExperimentAuditService::STATUS_SIGNATURE_MISMATCH]],
        'phase_3o_risky_rows' => [['audit_status' => AgenticPlannerApplyExperimentAuditService::STATUS_SIGNATURE_MISMATCH]],
    ], 'phase_3o_audit_has_risky_rows'],
    'phase 3l' => [[
        'canonical_proposed_default_order' => [
            ['rank' => 1, 'legacy_opportunity_id' => 'placeholder', 'canonical_opportunity_id' => 'placeholder', 'readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_METADATA_READY_ONLY],
        ],
        'phase_3l_readiness_rows' => [
            ['legacy_agentic_opportunity_id' => 'placeholder', 'readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_METADATA_READY_ONLY],
        ],
    ], 'canonical_proposed_candidate_is_not_phase_3l_ready'],
    'phase 3h' => [[
        'apply_safety' => ['phase_3h_signature_risk_count' => 1],
        'phase_3h_signature_equivalence' => [['legacy_opportunity_id' => 'placeholder', 'action_type' => AgenticMarketingActionType::CreateArticle->value, 'equivalent' => false]],
    ], 'phase_3h_signatures_do_not_match'],
    'phase 3i' => [['apply_safety' => ['phase_3i_continuity_risk_count' => 1]], 'phase_3i_continuity_has_blockers'],
    'phase 3j' => [['apply_safety' => ['phase_3j_lifecycle_risk_count' => 1]], 'phase_3j_lifecycle_has_ambiguity_or_conflict'],
    'duplicate' => [['apply_safety' => ['duplicate_open_action_risk_count' => 1]], 'duplicate_open_action_risk_exists'],
    'coverage' => [['apply_safety' => ['canonical_coverage_sufficient' => false]], 'canonical_coverage_is_not_sufficient'],
    'order' => [['apply_safety' => ['exact_order_match' => false]], 'canonical_proposed_order_does_not_exactly_match_legacy_order_for_scope'],
]);

it('applies Phase 3R by resolving canonical rows back to legacy actions and adding metadata only', function (): void {
    config(['features.mos_agentic_planner_canonical_default_selection_experiment' => true]);
    [, , , $objective] = phase3rContext('phase-3r-apply');
    $legacy = phase3rOpportunity($objective);
    $canonical = phase3rCanonical($legacy);
    $canonicalRecommendedActions = $canonical->recommended_actions;
    phase3rBindPreview(phase3rPreviewReport($objective, $legacy, $canonical));

    $this->artisan('mos:apply-agentic-planner-default-selection-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 1,
        '--apply' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Applying Phase 3R Agentic planner default-selection experiment.')
        ->expectsOutputToContain('created action count: 1')
        ->expectsOutputToContain('selected canonical opportunity ids: '.$canonical->id)
        ->expectsOutputToContain('resolved legacy Agentic opportunity ids: '.$legacy->id);

    $action = AgenticMarketingAction::query()->firstOrFail();
    $metadata = $action->payload['default_selection_experiment'];

    expect($action->opportunity_id)->toBe((string) $legacy->id)
        ->and($action->opportunity_id)->not->toBe((string) $canonical->id)
        ->and($metadata['version'])->toBe(AgenticCanonicalPlannerDefaultSelectionExperimentService::METADATA_VERSION)
        ->and($metadata['canonical_opportunity_id'])->toBe((string) $canonical->id)
        ->and($metadata['legacy_agentic_marketing_opportunity_id'])->toBe((string) $legacy->id)
        ->and($metadata['objective_id'])->toBe((string) $objective->id)
        ->and($metadata['workspace_id'])->toBe((string) $objective->workspace_id)
        ->and($metadata['selection_source'])->toBe('canonical_default_selection_experiment')
        ->and($metadata['phase_3q_preview_status'])->toBe('preview_safe')
        ->and($metadata['phase_3p_recommendation'])->toBe('continue_shadow')
        ->and($metadata['phase_3l_readiness_status'])->toBe(AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY)
        ->and($metadata['applied_by'])->toBe('command')
        ->and($canonical->refresh()->recommended_actions)->toBe($canonicalRecommendedActions)
        ->and(Opportunity::query()->count())->toBe(1);
});

it('keeps existing planner experiment metadata and does not change status or dedupe fields when Phase 3R metadata is added', function (): void {
    config(['features.mos_agentic_planner_canonical_default_selection_experiment' => true]);
    [, , , $objective] = phase3rContext('phase-3r-additive');
    $legacy = phase3rOpportunity($objective);
    $canonical = phase3rCanonical($legacy);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'title' => 'Existing Phase 3R action',
            'planner_experiment' => ['version' => 'existing'],
        ],
    ]);
    $original = $action->refresh()->only(['status', 'dedupe_hash', 'open_dedupe_hash', 'payload_hash', 'opportunity_id']);

    $preview = new class(phase3rPreviewReport($objective, $legacy, $canonical)) extends AgenticCanonicalPlannerDefaultSelectionPreviewService
    {
        public function __construct(private readonly array $report) {}

        public function preview(AgenticMarketingObjective $objective, array $filters = []): array
        {
            return $this->report;
        }
    };
    $planner = new class($action) extends AgenticMarketingActionPlanner
    {
        public function __construct(private readonly AgenticMarketingAction $action) {}

        public function previewPlannedActions(AgenticMarketingOpportunity $opportunity): array
        {
            return [[
                'action_type' => (string) $this->action->action_type,
                'estimated_credits' => 24,
                'approval_required' => false,
                'prerequisites' => ['met' => true],
                'payload' => ['title' => 'Existing Phase 3R action'],
            ]];
        }

        public function planForOpportunity(AgenticMarketingOpportunity $opportunity, ?AgenticMarketingRun $run = null): array
        {
            return [
                'opportunity_id' => (string) $opportunity->id,
                'created' => 0,
                'reused' => 1,
                'skipped' => 0,
                'action_ids' => [(string) $this->action->id],
            ];
        }
    };

    $report = (new AgenticCanonicalPlannerDefaultSelectionExperimentService($preview, $planner))
        ->run($objective, 1, [], true);

    $action->refresh();

    expect($report['summary']['blocked_count'])->toBe(0)
        ->and($action->status)->toBe($original['status'])
        ->and($action->dedupe_hash)->toBe($original['dedupe_hash'])
        ->and($action->open_dedupe_hash)->toBe($original['open_dedupe_hash'])
        ->and($action->payload_hash)->toBe($original['payload_hash'])
        ->and($action->opportunity_id)->toBe($original['opportunity_id'])
        ->and($action->payload['planner_experiment'])->toBe(['version' => 'existing'])
        ->and($action->payload['default_selection_experiment']['canonical_opportunity_id'])->toBe((string) $canonical->id);
});

it('leaves the default planner unchanged when Phase 3R flag is not enabled', function (): void {
    [, , , $objective] = phase3rContext('phase-3r-default-planner');
    $legacy = phase3rOpportunity($objective);
    $canonical = phase3rCanonical($legacy);
    $canonicalRecommendedActions = $canonical->recommended_actions;

    $summary = app(AgenticMarketingActionPlanner::class)->planForOpportunity($legacy);
    $action = AgenticMarketingAction::query()->firstOrFail();

    expect($summary['created'])->toBe(1)
        ->and($action->opportunity_id)->toBe((string) $legacy->id)
        ->and($action->payload)->not->toHaveKey('default_selection_experiment')
        ->and($canonical->refresh()->recommended_actions)->toBe($canonicalRecommendedActions);
});
