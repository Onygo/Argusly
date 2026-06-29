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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionCiEvidencePackageService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionOperatorSignOffRunbookService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeGuardService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeSwitchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase4fContext(string $slug = 'phase-4f'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 4F '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 4F Workspace',
        'display_name' => 'Phase 4F Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 4F Site',
        'site_url' => 'https://phase-4f.test',
        'base_url' => 'https://phase-4f.test',
        'allowed_domains' => ['phase-4f.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 4F objective '.Str::random(4),
        'goal' => 'Package readiness evidence for CI',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase4fOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 4F packaging evidence',
        'type' => AgenticMarketingOpportunityType::ContentNetwork->value,
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Phase 4F '.Str::lower(Str::random(8)),
            'reasoning' => 'Packaging is CI/review evidence only.',
            'recommendation' => 'Keep default planner legacy.',
            'signals' => ['topic_keyword' => 'Phase 4F'],
        ],
    ], $overrides));
}

function phase4fEvidenceReport(string $workspace = 'workspace-id', array $objectives = ['objective-id'], array $overrides = []): array
{
    return array_replace_recursive([
        'phase' => '4D',
        'workspace_id' => $workspace,
        'objective_ids' => $objectives,
        'real_scope_status' => [
            'workspace_id' => $workspace,
            'objective_ids' => $objectives,
            'workspace_record_found' => true,
            'objective_records_found' => true,
            'explicit_workspace_objective_scope' => true,
            'wildcard_scope_rejected' => false,
            'global_scope_rejected' => false,
            'percentage_scope_rejected' => true,
            'inferred_scope_rejected' => true,
            'real_scope_detected' => true,
        ],
        'telemetry_complete' => true,
        'blocked_reasons' => [],
        'audit_snapshot_status' => 'present',
        'activation_flag_state' => 'disabled_report_only_non_consuming',
        'activation_flag_consumed_for_switching' => false,
        'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
        'runtime_behavior_changed' => false,
        'final_evidence_status' => AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_READY,
    ], $overrides);
}

function phase4fSignoffReport(array $evidence = [], array $overrides = []): array
{
    $evidence = $evidence === [] ? phase4fEvidenceReport() : $evidence;

    return array_replace_recursive([
        'phase' => '4E',
        'workspace_id' => $evidence['workspace_id'],
        'objective_ids' => $evidence['objective_ids'],
        'evidence_status' => [
            'phase_4d_final_evidence_status' => $evidence['final_evidence_status'],
            'required_phase_4d_status' => AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_READY,
            'phase_4d_evidence_ready' => $evidence['final_evidence_status'] === AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_READY,
        ],
        'operator_review_status' => [
            'status' => AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::REVIEW_ACKNOWLEDGED,
            'operator_signoff_acknowledged' => true,
            'review_evidence_only' => true,
        ],
        'signoff_readiness' => [
            'status' => AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_READY,
            'ready' => true,
            'blocked_reasons' => [],
        ],
        'blocked_remediation_guidance' => [],
        'rollback_confirmation' => [
            'rollback_mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
            'legacy_first' => true,
            'legacy_output_remains_authoritative' => true,
            'additive_metadata_review_evidence_only' => true,
            'additive_metadata_required_for_rollback' => false,
            'metadata_removal_required_for_rollback' => false,
        ],
        'non_activation_confirmation' => [
            'activation_flag_consumed_for_switching' => false,
            'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'runtime_behavior_changed' => false,
            'planner_switching_activated' => false,
            'percentage_rollout_added' => false,
            'global_default_migration_performed' => false,
            'wildcard_scope_inferred' => false,
            'legacy_planner_output_replaced' => false,
            'agentic_marketing_action_created_or_mutated' => false,
            'ownership_migrated' => false,
            'lifecycle_synced' => false,
            'payload_status_dedupe_parent_approval_execution_mutated' => false,
            'runtime_audit_written' => false,
            'job_dispatched' => false,
            'route_or_approval_changed' => false,
            'historical_records_rewritten' => false,
        ],
        'phase_4d_evidence_report' => $evidence,
    ], $overrides);
}

function phase4fRunbookService(array $report): AgenticPlannerDefaultSelectionOperatorSignOffRunbookService
{
    return new class($report) extends AgenticPlannerDefaultSelectionOperatorSignOffRunbookService
    {
        public array $receivedInput = [];

        public function __construct(private readonly array $report) {}

        public function inspect(array $input): array
        {
            $this->receivedInput = $input;

            return $this->report;
        }
    };
}

function phase4fPackageService(array $report): AgenticPlannerDefaultSelectionCiEvidencePackageService
{
    return new AgenticPlannerDefaultSelectionCiEvidencePackageService(phase4fRunbookService($report));
}

function phase4fPackageInput(array $overrides = []): array
{
    return array_replace([
        'workspace' => 'workspace-id',
        'objectives' => ['objective-id'],
        'site' => 'site-id',
        'detector' => 'content_network_gaps',
        'limit' => 1,
        'ack_metadata_only_review' => true,
        'ack_runtime_switch_contract' => true,
        'ack_operator_signoff' => true,
        'require_real_scope' => true,
    ], $overrides);
}

it('returns package_ready for exact scope with Phase 4D evidence_ready and Phase 4E signoff_ready', function (): void {
    $package = phase4fPackageService(phase4fSignoffReport())->package(phase4fPackageInput());

    expect($package['package_status'])->toBe(AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_READY)
        ->and($package['phase_4d_final_evidence_status'])->toBe(AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_READY)
        ->and($package['phase_4e_signoff_readiness'])->toBe(AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_READY)
        ->and($package['workspace_id'])->toBe('workspace-id')
        ->and($package['objective_ids'])->toBe(['objective-id'])
        ->and(data_get($package, 'scope.site_id'))->toBe('site-id')
        ->and(data_get($package, 'scope.detector'))->toBe('content_network_gaps')
        ->and($package['blocked_reasons'])->toBe([])
        ->and($package['package_checksum'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($package['selected_planner_remains'])->toBe('legacy')
        ->and($package['runtime_behavior_changed'])->toBeFalse();
});

it('blocks the package when Phase 4D is not evidence_ready', function (): void {
    $evidence = phase4fEvidenceReport(overrides: [
        'final_evidence_status' => AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_BLOCKED,
        'blocked_reasons' => ['phase_4c_telemetry_incomplete'],
    ]);
    $signoff = phase4fSignoffReport($evidence, [
        'signoff_readiness' => [
            'status' => AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_BLOCKED,
            'ready' => false,
            'blocked_reasons' => ['phase_4c_telemetry_incomplete', 'phase_4d_evidence_ready_required'],
        ],
    ]);

    $package = phase4fPackageService($signoff)->package(phase4fPackageInput());

    expect($package['package_status'])->toBe(AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_BLOCKED)
        ->and($package['blocked_reasons'])->toContain('phase_4c_telemetry_incomplete')
        ->and($package['blocked_reasons'])->toContain('phase_4d_evidence_ready_required')
        ->and($package['blocked_reasons'])->toContain('phase_4e_signoff_ready_required');
});

it('blocks the package when Phase 4E sign-off is not signoff_ready', function (): void {
    $signoff = phase4fSignoffReport(overrides: [
        'operator_review_status' => [
            'status' => AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::REVIEW_MISSING,
            'operator_signoff_acknowledged' => false,
            'review_evidence_only' => true,
        ],
        'signoff_readiness' => [
            'status' => AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_BLOCKED,
            'ready' => false,
            'blocked_reasons' => ['operator_signoff_acknowledgement_missing'],
        ],
    ]);

    $package = phase4fPackageService($signoff)->package(phase4fPackageInput());

    expect($package['package_status'])->toBe(AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_BLOCKED)
        ->and($package['phase_4d_final_evidence_status'])->toBe(AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_READY)
        ->and($package['blocked_reasons'])->toContain('operator_signoff_acknowledgement_missing')
        ->and($package['blocked_reasons'])->toContain('phase_4e_signoff_ready_required');
});

it('blocks missing exact real scope instead of inferring wildcard scope', function (): void {
    $evidence = phase4fEvidenceReport(overrides: [
        'real_scope_status' => [
            'real_scope_detected' => false,
            'explicit_workspace_objective_scope' => false,
        ],
    ]);

    $package = phase4fPackageService(phase4fSignoffReport($evidence))->package(phase4fPackageInput([
        'require_real_scope' => false,
    ]));

    expect($package['package_status'])->toBe(AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_BLOCKED)
        ->and($package['blocked_reasons'])->toContain('exact_real_scope_required')
        ->and(data_get($package, 'scope.require_real_scope'))->toBeFalse()
        ->and(data_get($package, 'scope.wildcard_scope_inferred'))->toBeFalse();
});

it('uses a deterministic checksum for the same package content while excluding generated_at', function (): void {
    $service = phase4fPackageService(phase4fSignoffReport());

    $first = $service->package(phase4fPackageInput());
    sleep(1);
    $second = $service->package(phase4fPackageInput());

    expect($first['package_checksum'])->toBe($second['package_checksum'])
        ->and($first['package_checksum_scope'])->toBe('canonical_package_excluding_generated_at_and_checksum_fields');
});

it('keeps rollback legacy-first in the package and blocks non legacy-first rollback', function (): void {
    $ready = phase4fPackageService(phase4fSignoffReport())->package(phase4fPackageInput());

    expect(data_get($ready, 'rollback_confirmation.rollback_mode'))->toBe(AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE)
        ->and(data_get($ready, 'rollback_confirmation.legacy_first'))->toBeTrue()
        ->and(data_get($ready, 'rollback_confirmation.metadata_removal_required_for_rollback'))->toBeFalse();

    $blocked = phase4fPackageService(phase4fSignoffReport(overrides: [
        'rollback_confirmation' => [
            'rollback_mode' => 'canonical_first',
            'legacy_first' => false,
        ],
    ]))->package(phase4fPackageInput());

    expect($blocked['package_status'])->toBe(AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_BLOCKED)
        ->and($blocked['blocked_reasons'])->toContain('rollback_must_remain_legacy_first');
});

it('does not mutate planner output actions ownership lifecycle payload status dedupe audits routes approvals or jobs', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase4fContext('phase-4f-no-mutations');
    $legacy = phase4fOpportunity($objective);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'title' => 'Existing Phase 4F action',
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

    $package = phase4fPackageService(phase4fSignoffReport(phase4fEvidenceReport((string) $workspace->id, [(string) $objective->id])))->package(phase4fPackageInput([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
    ]));

    expect($package['package_status'])->toBe(AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_READY)
        ->and(data_get($package, 'non_activation_confirmations.selected_planner_remains'))->toBe('legacy')
        ->and($package['runtime_behavior_changed'])->toBeFalse()
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

it('command exits zero for blocked review output without ci and non-zero with ci', function (): void {
    app()->instance(
        AgenticPlannerDefaultSelectionCiEvidencePackageService::class,
        phase4fPackageService(phase4fSignoffReport(overrides: [
            'signoff_readiness' => [
                'status' => AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_BLOCKED,
                'ready' => false,
                'blocked_reasons' => ['operator_signoff_acknowledgement_missing'],
            ],
        ]))
    );

    $arguments = [
        '--workspace' => 'workspace-id',
        '--objectives' => 'objective-id',
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
        '--require-real-scope' => true,
    ];

    $this->artisan('mos:package-agentic-planner-default-selection-readiness-evidence', $arguments)
        ->assertSuccessful()
        ->expectsOutputToContain('Packaging evidence only')
        ->expectsOutputToContain('Package status')
        ->expectsOutputToContain('Exact scope summary')
        ->expectsOutputToContain('Rollback confirmation')
        ->expectsOutputToContain('package checksum:')
        ->expectsOutputToContain('final package status: package_blocked');

    $this->artisan('mos:package-agentic-planner-default-selection-readiness-evidence', array_replace($arguments, [
        '--ci' => true,
    ]))
        ->assertExitCode(1)
        ->expectsOutputToContain('final package status: package_blocked');
});

it('command exits zero for a ready package in ci mode', function (): void {
    app()->instance(
        AgenticPlannerDefaultSelectionCiEvidencePackageService::class,
        phase4fPackageService(phase4fSignoffReport())
    );

    $this->artisan('mos:package-agentic-planner-default-selection-readiness-evidence', [
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
        ->expectsOutputToContain('final package status: package_ready')
        ->expectsOutputToContain('selected planner remains: legacy')
        ->expectsOutputToContain('runtime_behavior_changed: no');
});
