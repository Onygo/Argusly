<?php

use App\Enums\AgenticMarketingActionType;
use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingAuditLog;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingRun;
use App\Models\AgenticMarketingRunItem;
use App\Models\AgenticPlannerDefaultSelectionRuntimeSwitchAudit;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionOperatorSignOffRunbookService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionRuntimeSwitchContractService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeGuardService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeSwitchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase4eContext(string $slug = 'phase-4e'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 4E '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 4E Workspace',
        'display_name' => 'Phase 4E Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 4E Site',
        'site_url' => 'https://phase-4e.test',
        'base_url' => 'https://phase-4e.test',
        'allowed_domains' => ['phase-4e.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 4E objective '.Str::random(4),
        'goal' => 'Review operator sign-off runbook',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase4eOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 4E sign-off runbook',
        'type' => AgenticMarketingOpportunityType::ContentNetwork->value,
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Phase 4E '.Str::lower(Str::random(8)),
            'reasoning' => 'Operator sign-off is review evidence only.',
            'recommendation' => 'Keep default planner legacy.',
            'signals' => ['topic_keyword' => 'Phase 4E'],
        ],
    ], $overrides));
}

function phase4eEvidenceReport(string $workspace = 'workspace-id', array $objectives = ['objective-id'], array $overrides = []): array
{
    return array_replace_recursive([
        'phase' => '4D',
        'workspace_id' => $workspace,
        'objective_ids' => $objectives,
        'real_scope_status' => [
            'workspace_id' => $workspace,
            'objective_ids' => $objectives,
            'real_scope_detected' => true,
        ],
        'telemetry_complete' => true,
        'blocked_reasons' => [],
        'audit_snapshot_status' => 'present',
        'activation_flag_state' => 'disabled_report_only_non_consuming',
        'activation_flag_consumed_for_switching' => false,
        'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
        'runtime_behavior_changed' => false,
        'non_activation_checklist' => [],
        'rollback_checklist' => [
            [
                'id' => 'rollback_path_confirmed_legacy_first',
                'required' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
                'actual' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
                'passed' => true,
            ],
            [
                'id' => 'metadata_removal_not_required_for_rollback',
                'required' => true,
                'actual' => true,
                'passed' => true,
            ],
        ],
        'operator_approval_checklist' => [],
        'phase_3t_through_4c_chain_summary' => [
            'phase_3t' => ['status' => 'ready_for_scoped_expansion'],
            'phase_3u' => ['status' => 'eligible'],
            'phase_3x' => ['status' => AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY],
            'phase_4c' => ['status' => 'telemetry_complete'],
        ],
        'final_evidence_status' => AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_READY,
    ], $overrides);
}

function phase4eEvidenceService(array $report): AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService
{
    return new class($report) extends AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService
    {
        public array $receivedInput = [];

        public function __construct(private readonly array $report) {}

        public function evidence(array $input): array
        {
            $this->receivedInput = $input;

            return $this->report;
        }
    };
}

function phase4eRunbookService(array $report): AgenticPlannerDefaultSelectionOperatorSignOffRunbookService
{
    return new AgenticPlannerDefaultSelectionOperatorSignOffRunbookService(phase4eEvidenceService($report));
}

it('returns signoff_ready only when Phase 4D is evidence_ready and operator sign-off is acknowledged', function (): void {
    $report = phase4eRunbookService(phase4eEvidenceReport())->inspect([
        'workspace' => 'workspace-id',
        'objectives' => ['objective-id'],
        'limit' => 1,
        'ack_metadata_only_review' => true,
        'ack_runtime_switch_contract' => true,
        'ack_operator_signoff' => true,
        'require_real_scope' => true,
    ]);

    expect(data_get($report, 'evidence_status.phase_4d_final_evidence_status'))->toBe(AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_READY)
        ->and(data_get($report, 'operator_review_status.status'))->toBe(AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::REVIEW_ACKNOWLEDGED)
        ->and(data_get($report, 'signoff_readiness.status'))->toBe(AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_READY)
        ->and(data_get($report, 'signoff_readiness.blocked_reasons'))->toBe([])
        ->and(data_get($report, 'blocked_remediation_guidance'))->toBe([])
        ->and(data_get($report, 'rollback_confirmation.rollback_mode'))->toBe(AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE)
        ->and(data_get($report, 'rollback_confirmation.additive_metadata_review_evidence_only'))->toBeTrue()
        ->and(data_get($report, 'rollback_confirmation.additive_metadata_required_for_rollback'))->toBeFalse()
        ->and(data_get($report, 'rollback_confirmation.metadata_removal_required_for_rollback'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.selected_planner_remains'))->toBe('legacy')
        ->and(data_get($report, 'non_activation_confirmation.activation_flag_consumed_for_switching'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.runtime_behavior_changed'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.planner_switching_activated'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.percentage_rollout_added'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.global_default_migration_performed'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.wildcard_scope_inferred'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.legacy_planner_output_replaced'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.agentic_marketing_action_created_or_mutated'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.ownership_migrated'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.lifecycle_synced'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.payload_status_dedupe_parent_approval_execution_mutated'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.runtime_audit_written'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.job_dispatched'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.route_or_approval_changed'))->toBeFalse()
        ->and(data_get($report, 'non_activation_confirmation.historical_records_rewritten'))->toBeFalse();
});

it('blocks sign-off when Phase 4D is not evidence_ready and includes remediation guidance for every blocked reason', function (): void {
    $report = phase4eRunbookService(phase4eEvidenceReport(overrides: [
        'telemetry_complete' => false,
        'blocked_reasons' => [
            'phase_3t_ready_for_scoped_expansion',
            'phase_4c_telemetry_incomplete',
            'matching_audit_snapshot_present',
            'metadata_only_ok_reviewed',
        ],
        'final_evidence_status' => AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_BLOCKED,
    ]))->inspect([
        'workspace' => 'workspace-id',
        'objectives' => ['objective-id'],
        'limit' => 1,
        'ack_operator_signoff' => true,
        'require_real_scope' => true,
    ]);

    $blockedReasons = data_get($report, 'signoff_readiness.blocked_reasons');
    $guidanceReasons = collect($report['blocked_remediation_guidance'])->pluck('reason')->all();

    expect(data_get($report, 'signoff_readiness.status'))->toBe(AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_BLOCKED)
        ->and($blockedReasons)->toContain(
            'phase_3t_ready_for_scoped_expansion',
            'phase_4c_telemetry_incomplete',
            'matching_audit_snapshot_present',
            'metadata_only_ok_reviewed',
            'phase_4d_evidence_ready_required',
        )
        ->and($guidanceReasons)->toEqual($blockedReasons)
        ->and(collect($report['blocked_remediation_guidance'])->pluck('guidance')->filter()->count())->toBe(count($blockedReasons))
        ->and(collect($report['blocked_remediation_guidance'])->firstWhere('reason', 'phase_3t_ready_for_scoped_expansion')['guidance'])->toContain('Re-run Phase 3T readiness');
});

it('blocks sign-off when explicit operator acknowledgement is missing', function (): void {
    $report = phase4eRunbookService(phase4eEvidenceReport())->inspect([
        'workspace' => 'workspace-id',
        'objectives' => ['objective-id'],
        'limit' => 1,
        'ack_metadata_only_review' => true,
        'ack_runtime_switch_contract' => true,
        'ack_operator_signoff' => false,
        'require_real_scope' => true,
    ]);

    expect(data_get($report, 'operator_review_status.status'))->toBe(AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::REVIEW_MISSING)
        ->and(data_get($report, 'operator_review_status.review_evidence_only'))->toBeTrue()
        ->and(data_get($report, 'signoff_readiness.status'))->toBe(AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_BLOCKED)
        ->and(data_get($report, 'signoff_readiness.blocked_reasons'))->toContain('operator_signoff_acknowledgement_missing');
});

it('command exits zero for blocked review output without ci and non-zero with ci', function (): void {
    app()->instance(
        AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::class,
        phase4eRunbookService(phase4eEvidenceReport(overrides: [
            'blocked_reasons' => ['phase_4c_telemetry_incomplete'],
            'final_evidence_status' => AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_BLOCKED,
        ]))
    );

    $this->artisan('mos:inspect-agentic-planner-default-selection-operator-signoff-runbook', [
        '--workspace' => 'workspace-id',
        '--objectives' => 'objective-id',
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
        '--ack-operator-signoff' => true,
        '--require-real-scope' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Evidence status')
        ->expectsOutputToContain('Operator review status')
        ->expectsOutputToContain('Sign-off readiness')
        ->expectsOutputToContain('Blocked remediation guidance')
        ->expectsOutputToContain('Rollback confirmation')
        ->expectsOutputToContain('final sign-off readiness: signoff_blocked');

    $this->artisan('mos:inspect-agentic-planner-default-selection-operator-signoff-runbook', [
        '--workspace' => 'workspace-id',
        '--objectives' => 'objective-id',
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
        '--ack-operator-signoff' => true,
        '--require-real-scope' => true,
        '--ci' => true,
    ])
        ->assertExitCode(1)
        ->expectsOutputToContain('final sign-off readiness: signoff_blocked');
});

it('command exits zero for ready sign-off', function (): void {
    app()->instance(
        AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::class,
        phase4eRunbookService(phase4eEvidenceReport())
    );

    $this->artisan('mos:inspect-agentic-planner-default-selection-operator-signoff-runbook', [
        '--workspace' => 'workspace-id',
        '--objectives' => 'objective-id',
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
        '--ack-operator-signoff' => true,
        '--require-real-scope' => true,
        '--ci' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('final sign-off readiness: signoff_ready')
        ->expectsOutputToContain('selected planner remains: legacy')
        ->expectsOutputToContain('activation_flag_consumed_for_switching: no')
        ->expectsOutputToContain('runtime_behavior_changed: no');
});

it('does not mutate planner output actions ownership lifecycle payload status dedupe audits routes approvals or jobs', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase4eContext('phase-4e-no-mutations');
    $legacy = phase4eOpportunity($objective);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'title' => 'Existing Phase 4E action',
            'planning' => ['planner' => 'legacy-planner'],
            'approval' => ['route' => 'legacy-approval', 'required' => true],
        ],
    ]);

    $planner = app(AgenticMarketingActionPlanner::class);
    $plannerOutputBefore = $planner->previewPlannedActions($legacy);
    $before = [
        'legacy' => $legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']),
        'action' => $action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']),
        'actions' => AgenticMarketingAction::query()->count(),
        'opportunities' => AgenticMarketingOpportunity::query()->count(),
        'runs' => AgenticMarketingRun::query()->count(),
        'run_items' => AgenticMarketingRunItem::query()->count(),
        'action_runs' => AgenticActionRun::query()->count(),
        'audit_logs' => AgenticMarketingAuditLog::query()->count(),
        'canonical' => Opportunity::query()->count(),
        'runtime_switch_audits' => AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count(),
        'routes' => Route::getRoutes()->count(),
    ];

    $report = phase4eRunbookService(phase4eEvidenceReport((string) $workspace->id, [(string) $objective->id]))->inspect([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'limit' => 1,
        'ack_metadata_only_review' => true,
        'ack_runtime_switch_contract' => true,
        'ack_operator_signoff' => true,
        'require_real_scope' => true,
    ]);

    expect(data_get($report, 'signoff_readiness.status'))->toBe(AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_READY)
        ->and(data_get($report, 'non_activation_confirmation.selected_planner_remains'))->toBe('legacy')
        ->and(data_get($report, 'non_activation_confirmation.runtime_behavior_changed'))->toBeFalse()
        ->and($planner->previewPlannedActions($legacy->refresh()))->toBe($plannerOutputBefore)
        ->and($legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']))->toBe($before['legacy'])
        ->and($action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']))->toBe($before['action'])
        ->and(AgenticMarketingAction::query()->count())->toBe($before['actions'])
        ->and(AgenticMarketingOpportunity::query()->count())->toBe($before['opportunities'])
        ->and(AgenticMarketingRun::query()->count())->toBe($before['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($before['run_items'])
        ->and(AgenticActionRun::query()->count())->toBe($before['action_runs'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($before['audit_logs'])
        ->and(Opportunity::query()->count())->toBe($before['canonical'])
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe($before['runtime_switch_audits'])
        ->and(Route::getRoutes()->count())->toBe($before['routes']);

    Bus::assertNothingDispatched();
});
