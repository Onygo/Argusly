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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionPreActivationRehearsalService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeSwitchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase4gPackage(array $overrides = []): array
{
    return array_replace_recursive([
        'phase' => '4F',
        'mode' => AgenticPlannerDefaultSelectionCiEvidencePackageService::MODE,
        'package_status' => AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_READY,
        'workspace_id' => 'workspace-id',
        'objective_ids' => ['objective-id'],
        'scope' => [
            'workspace_id' => 'workspace-id',
            'objective_ids' => ['objective-id'],
            'objective_count' => 1,
            'site_id' => 'site-id',
            'detector' => 'content_network_gaps',
            'limit' => 1,
            'require_real_scope' => true,
            'real_scope_detected' => true,
            'explicit_workspace_objective_scope' => true,
            'wildcard_scope_inferred' => false,
            'percentage_scope_used' => false,
            'global_scope_used' => false,
        ],
        'blocked_reasons' => [],
        'remediation_guidance' => [],
        'rollback_confirmation' => [
            'rollback_mode' => 'legacy_first',
            'legacy_first' => true,
            'legacy_output_remains_authoritative' => true,
            'additive_metadata_required_for_rollback' => false,
            'metadata_removal_required_for_rollback' => false,
        ],
        'non_activation_confirmations' => [
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
            'runtime_feature_flags_introduced' => false,
        ],
        'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
        'runtime_behavior_changed' => false,
        'package_checksum_algorithm' => 'sha256',
        'package_checksum' => str_repeat('a', 64),
        'package_checksum_scope' => 'canonical_package_excluding_generated_at_and_checksum_fields',
    ], $overrides);
}

function phase4gPackageService(array $package): AgenticPlannerDefaultSelectionCiEvidencePackageService
{
    return new class($package) extends AgenticPlannerDefaultSelectionCiEvidencePackageService
    {
        public array $receivedInput = [];

        public function __construct(private readonly array $package) {}

        public function package(array $input): array
        {
            $this->receivedInput = $input;

            return $this->package;
        }
    };
}

function phase4gRehearsalService(array $package): AgenticPlannerDefaultSelectionPreActivationRehearsalService
{
    return new AgenticPlannerDefaultSelectionPreActivationRehearsalService(phase4gPackageService($package));
}

function phase4gInput(array $overrides = []): array
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

function phase4gCommandService(array $report): AgenticPlannerDefaultSelectionPreActivationRehearsalService
{
    return new class($report) extends AgenticPlannerDefaultSelectionPreActivationRehearsalService
    {
        public function __construct(private readonly array $report) {}

        public function rehearse(array $input): array
        {
            return $this->report;
        }
    };
}

function phase4gContext(string $slug = 'phase-4g'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 4G '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 4G Workspace',
        'display_name' => 'Phase 4G Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 4G Site',
        'site_url' => 'https://phase-4g.test',
        'base_url' => 'https://phase-4g.test',
        'allowed_domains' => ['phase-4g.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 4G objective '.Str::random(4),
        'goal' => 'Rehearse activation readiness without activating',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase4gOpportunity(AgenticMarketingObjective $objective): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create([
        'objective_id' => $objective->id,
        'title' => 'Phase 4G rehearsal evidence',
        'type' => AgenticMarketingOpportunityType::ContentNetwork->value,
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Phase 4G '.Str::lower(Str::random(8)),
            'reasoning' => 'Rehearsal is dry-run evidence only.',
            'recommendation' => 'Keep default planner legacy.',
            'signals' => ['topic_keyword' => 'Phase 4G'],
        ],
    ]);
}

it('returns rehearsal_ready for exact scope with a Phase 4F package_ready artifact', function (): void {
    $report = phase4gRehearsalService(phase4gPackage())->rehearse(phase4gInput());

    expect($report['rehearsal_status'])->toBe(AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_READY)
        ->and($report['phase_4f_package_status'])->toBe(AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_READY)
        ->and($report['package_checksum'])->toBe(str_repeat('a', 64))
        ->and($report['selected_planner_remains'])->toBe('legacy')
        ->and($report['runtime_behavior_changed'])->toBeFalse()
        ->and(data_get($report, 'rehearsal_activation_plan.plan_type'))->toBe('dry_run_only')
        ->and(data_get($report, 'rehearsal_activation_plan.activation_performed'))->toBeFalse()
        ->and(data_get($report, 'rehearsal_activation_plan.activation_flags_consumed'))->toBeFalse()
        ->and($report['blocked_reasons'])->toBe([])
        ->and(data_get($report, 'scope.wildcard_scope_inferred'))->toBeFalse();
});

it('blocks rehearsal when the Phase 4F package is not package_ready', function (): void {
    $report = phase4gRehearsalService(phase4gPackage([
        'package_status' => AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_BLOCKED,
        'blocked_reasons' => ['phase_4e_signoff_ready_required'],
        'remediation_guidance' => [
            ['reason' => 'phase_4e_signoff_ready_required', 'guidance' => 'Re-run Phase 4E sign-off.'],
        ],
    ]))->rehearse(phase4gInput());

    expect($report['rehearsal_status'])->toBe(AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('phase_4e_signoff_ready_required')
        ->and($report['blocked_reasons'])->toContain('phase_4f_package_ready_required')
        ->and(collect($report['remediation_guidance'])->pluck('reason')->all())->toContain('phase_4f_package_ready_required');
});

it('blocks missing exact real scope instead of inferring wildcard scope', function (): void {
    $report = phase4gRehearsalService(phase4gPackage([
        'scope' => [
            'real_scope_detected' => false,
            'explicit_workspace_objective_scope' => false,
        ],
    ]))->rehearse(phase4gInput([
        'require_real_scope' => false,
    ]));

    expect($report['rehearsal_status'])->toBe(AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('exact_real_scope_required')
        ->and(data_get($report, 'scope.require_real_scope'))->toBeFalse()
        ->and(data_get($report, 'scope.wildcard_scope_inferred'))->toBeFalse();
});

it('blocks wildcard percentage or global scope from the Phase 4F package', function (): void {
    $report = phase4gRehearsalService(phase4gPackage([
        'scope' => [
            'wildcard_scope_inferred' => true,
            'percentage_scope_used' => true,
            'global_scope_used' => true,
        ],
    ]))->rehearse(phase4gInput());

    expect($report['rehearsal_status'])->toBe(AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('exact_real_scope_required')
        ->and(data_get($report, 'scope.wildcard_scope_inferred'))->toBeTrue()
        ->and(data_get($report, 'scope.percentage_scope_used'))->toBeTrue()
        ->and(data_get($report, 'scope.global_scope_used'))->toBeTrue();
});

it('blocks percentage or global scope even when wildcard scope is not inferred', function (): void {
    $report = phase4gRehearsalService(phase4gPackage([
        'scope' => [
            'wildcard_scope_inferred' => false,
            'percentage_scope_used' => true,
            'global_scope_used' => true,
        ],
    ]))->rehearse(phase4gInput());

    expect($report['rehearsal_status'])->toBe(AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('exact_real_scope_required')
        ->and(data_get($report, 'scope.wildcard_scope_inferred'))->toBeFalse()
        ->and(data_get($report, 'scope.percentage_scope_used'))->toBeTrue()
        ->and(data_get($report, 'scope.global_scope_used'))->toBeTrue();
});

it('rehearses rollback as legacy-first without requiring metadata removal or runtime mutations', function (): void {
    $report = phase4gRehearsalService(phase4gPackage())->rehearse(phase4gInput());

    expect(data_get($report, 'rollback_rehearsal_result.passed'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_result.legacy_planner_output_remains_authoritative'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_result.legacy_agentic_action_ownership_remains_authoritative'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_result.future_activation_disable_keeps_legacy_output_selected'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_result.metadata_removal_required_for_rollback'))->toBeFalse()
        ->and(data_get($report, 'rollback_rehearsal_result.ownership_migration_required'))->toBeFalse()
        ->and(data_get($report, 'rollback_rehearsal_result.lifecycle_sync_required'))->toBeFalse()
        ->and(data_get($report, 'rollback_rehearsal_result.payload_status_dedupe_parent_approval_execution_changes_required'))->toBeFalse()
        ->and(data_get($report, 'legacy_first_confirmation.legacy_planner_output_authoritative'))->toBeTrue()
        ->and(data_get($report, 'legacy_first_confirmation.legacy_agentic_action_ownership_authoritative'))->toBeTrue();
});

it('blocks rollback rehearsal when Phase 4F says rollback needs runtime or history changes', function (): void {
    $report = phase4gRehearsalService(phase4gPackage([
        'rollback_confirmation' => [
            'metadata_removal_required_for_rollback' => true,
            'ownership_migration_required' => true,
            'lifecycle_sync_required' => true,
            'payload_status_dedupe_parent_approval_execution_changes_required' => true,
            'historical_rewrite_required' => true,
            'runtime_audit_write_required' => true,
            'route_or_approval_change_required' => true,
            'job_dispatch_required' => true,
        ],
    ]))->rehearse(phase4gInput());

    expect($report['rehearsal_status'])->toBe(AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('rollback_rehearsal_must_be_legacy_first')
        ->and(data_get($report, 'rollback_rehearsal_result.passed'))->toBeFalse()
        ->and(data_get($report, 'rollback_rehearsal_result.metadata_removal_required_for_rollback'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_result.ownership_migration_required'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_result.lifecycle_sync_required'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_result.payload_status_dedupe_parent_approval_execution_changes_required'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_result.historical_rewrite_required'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_result.runtime_audit_write_required'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_result.route_or_approval_change_required'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_result.job_dispatch_required'))->toBeTrue();
});

it('blocks each forbidden non-activation confirmation explicitly', function (string $key): void {
    $report = phase4gRehearsalService(phase4gPackage([
        'non_activation_confirmations' => [
            $key => $key === 'selected_planner_remains' ? 'canonical' : true,
        ],
    ]))->rehearse(phase4gInput());

    expect(array_keys($report['non_activation_confirmations']))->toContain($key)
        ->and($report['rehearsal_status'])->toBe(AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('non_activation_confirmation_required');
})->with([
    'activation_flag_consumed_for_switching',
    'selected_planner_remains',
    'runtime_behavior_changed',
    'planner_switching_activated',
    'percentage_rollout_added',
    'global_default_migration_performed',
    'wildcard_scope_inferred',
    'legacy_planner_output_replaced',
    'agentic_marketing_action_created_or_mutated',
    'ownership_migrated',
    'lifecycle_synced',
    'payload_status_dedupe_parent_approval_execution_mutated',
    'runtime_audit_written',
    'job_dispatched',
    'route_or_approval_changed',
    'historical_records_rewritten',
    'runtime_feature_flags_introduced',
]);

it('preserves the deterministic Phase 4F package checksum unchanged', function (): void {
    $checksum = hash('sha256', 'phase-4f-package');
    $service = phase4gRehearsalService(phase4gPackage([
        'package_checksum' => $checksum,
    ]));

    $first = $service->rehearse(phase4gInput());
    sleep(1);
    $second = $service->rehearse(phase4gInput());

    expect($first['package_checksum'])->toBe($checksum)
        ->and($second['package_checksum'])->toBe($checksum)
        ->and($first['package_checksum'])->toBe($second['package_checksum'])
        ->and(data_get($first, 'phase_4f_package_report.package_checksum'))->toBe($checksum);
});

it('does not mutate planner output actions ownership lifecycle payload status dedupe audits routes approvals or jobs', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase4gContext('phase-4g-no-mutations');
    $legacy = phase4gOpportunity($objective);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'title' => 'Existing Phase 4G action',
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

    $report = phase4gRehearsalService(phase4gPackage([
        'workspace_id' => (string) $workspace->id,
        'objective_ids' => [(string) $objective->id],
        'scope' => [
            'workspace_id' => (string) $workspace->id,
            'objective_ids' => [(string) $objective->id],
            'real_scope_detected' => true,
            'explicit_workspace_objective_scope' => true,
        ],
    ]))->rehearse(phase4gInput([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
    ]));

    expect($report['rehearsal_status'])->toBe(AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_READY)
        ->and(data_get($report, 'non_activation_confirmations.selected_planner_remains'))->toBe('legacy')
        ->and($report['runtime_behavior_changed'])->toBeFalse()
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
    $blocked = phase4gRehearsalService(phase4gPackage([
        'package_status' => AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_BLOCKED,
        'blocked_reasons' => ['phase_4e_signoff_ready_required'],
    ]))->rehearse(phase4gInput());

    app()->instance(
        AgenticPlannerDefaultSelectionPreActivationRehearsalService::class,
        phase4gCommandService($blocked)
    );

    $arguments = [
        '--workspace' => 'workspace-id',
        '--objectives' => 'objective-id',
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
        '--ack-operator-signoff' => true,
        '--require-real-scope' => true,
    ];

    $this->artisan('mos:rehearse-agentic-planner-default-selection-pre-activation', $arguments)
        ->assertSuccessful()
        ->expectsOutputToContain('Dry-run rehearsal only')
        ->expectsOutputToContain('Rehearsal status')
        ->expectsOutputToContain('Dry-run activation plan')
        ->expectsOutputToContain('Rollback rehearsal result')
        ->expectsOutputToContain('final rehearsal status: rehearsal_blocked');

    $this->artisan('mos:rehearse-agentic-planner-default-selection-pre-activation', array_replace($arguments, [
        '--ci' => true,
    ]))
        ->assertExitCode(1)
        ->expectsOutputToContain('final rehearsal status: rehearsal_blocked');
});

it('command exits zero for a ready rehearsal in ci mode', function (): void {
    $ready = phase4gRehearsalService(phase4gPackage())->rehearse(phase4gInput());

    app()->instance(
        AgenticPlannerDefaultSelectionPreActivationRehearsalService::class,
        phase4gCommandService($ready)
    );

    $this->artisan('mos:rehearse-agentic-planner-default-selection-pre-activation', [
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
        ->expectsOutputToContain('final rehearsal status: rehearsal_ready')
        ->expectsOutputToContain('selected planner remains: legacy')
        ->expectsOutputToContain('runtime_behavior_changed: no');
});

it('includes remediation guidance for package and scope blockers', function (): void {
    $report = phase4gRehearsalService(phase4gPackage([
        'package_status' => AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_BLOCKED,
        'blocked_reasons' => ['phase_4d_evidence_ready_required'],
        'scope' => [
            'real_scope_detected' => false,
            'explicit_workspace_objective_scope' => false,
        ],
    ]))->rehearse(phase4gInput([
        'require_real_scope' => false,
    ]));

    $guidance = collect($report['remediation_guidance'])->pluck('guidance', 'reason');

    expect($report['rehearsal_status'])->toBe(AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_BLOCKED)
        ->and($guidance->keys()->all())->toContain('phase_4d_evidence_ready_required')
        ->and($guidance->keys()->all())->toContain('exact_real_scope_required')
        ->and($guidance->keys()->all())->toContain('phase_4f_package_ready_required')
        ->and($guidance->get('exact_real_scope_required'))->toContain('--require-real-scope')
        ->and($guidance->get('phase_4f_package_ready_required'))->toContain('package_status=package_ready');
});
