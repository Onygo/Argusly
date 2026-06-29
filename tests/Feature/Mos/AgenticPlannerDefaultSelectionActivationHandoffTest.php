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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionActivationHandoffService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionCiEvidencePackageService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionPreActivationRehearsalService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeSwitchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase4hRehearsal(array $overrides = []): array
{
    return array_replace_recursive([
        'phase' => '4G',
        'mode' => AgenticPlannerDefaultSelectionPreActivationRehearsalService::MODE,
        'rehearsal_status' => AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_READY,
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
        'phase_4f_package_status' => AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_READY,
        'package_checksum' => str_repeat('b', 64),
        'package_checksum_algorithm' => 'sha256',
        'package_checksum_scope' => 'canonical_package_excluding_generated_at_and_checksum_fields',
        'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
        'runtime_behavior_changed' => false,
        'rehearsal_activation_plan' => [
            'plan_type' => 'dry_run_only',
            'activation_rehearsed' => true,
            'activation_performed' => false,
            'activation_flags_consumed' => false,
            'future_activation_required' => true,
            'selected_planner_during_rehearsal' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'runtime_behavior_changed' => false,
            'steps' => [
                'confirm_phase_4f_package_ready',
                'confirm_exact_workspace_objective_scope',
                'confirm_legacy_output_remains_authoritative',
                'confirm_rollback_keeps_legacy_selected',
                'stop_before_runtime_activation',
            ],
        ],
        'rollback_rehearsal_result' => [
            'status' => 'rollback_rehearsed_legacy_first',
            'passed' => true,
            'legacy_planner_output_remains_authoritative' => true,
            'legacy_agentic_action_ownership_remains_authoritative' => true,
            'future_activation_disable_keeps_legacy_output_selected' => true,
            'metadata_removal_required_for_rollback' => false,
            'ownership_migration_required' => false,
            'lifecycle_sync_required' => false,
            'payload_status_dedupe_parent_approval_execution_changes_required' => false,
            'historical_rewrite_required' => false,
            'runtime_audit_write_required' => false,
            'route_or_approval_change_required' => false,
            'job_dispatch_required' => false,
        ],
        'legacy_first_confirmation' => [
            'legacy_planner_output_authoritative' => true,
            'legacy_agentic_action_ownership_authoritative' => true,
            'selected_planner_after_rehearsal' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'canonical_output_rehearsed_as_evidence_only' => true,
        ],
        'blocked_reasons' => [],
        'remediation_guidance' => [],
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
    ], $overrides);
}

function phase4hRehearsalService(array $report): AgenticPlannerDefaultSelectionPreActivationRehearsalService
{
    return new class($report) extends AgenticPlannerDefaultSelectionPreActivationRehearsalService
    {
        public array $receivedInput = [];

        public function __construct(private readonly array $report) {}

        public function rehearse(array $input): array
        {
            $this->receivedInput = $input;

            return $this->report;
        }
    };
}

function phase4hService(array $report): AgenticPlannerDefaultSelectionActivationHandoffService
{
    return new AgenticPlannerDefaultSelectionActivationHandoffService(phase4hRehearsalService($report));
}

function phase4hInput(array $overrides = []): array
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
        'ack_activation_handoff' => true,
        'require_real_scope' => true,
    ], $overrides);
}

function phase4hCommandService(array $report): AgenticPlannerDefaultSelectionActivationHandoffService
{
    return new class($report) extends AgenticPlannerDefaultSelectionActivationHandoffService
    {
        public function __construct(private readonly array $report) {}

        public function handoff(array $input): array
        {
            return $this->report;
        }
    };
}

function phase4hContext(string $slug = 'phase-4h'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 4H '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 4H Workspace',
        'display_name' => 'Phase 4H Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 4H Site',
        'site_url' => 'https://phase-4h.test',
        'base_url' => 'https://phase-4h.test',
        'allowed_domains' => ['phase-4h.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 4H objective '.Str::random(4),
        'goal' => 'Prepare operator handoff without activating',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase4hOpportunity(AgenticMarketingObjective $objective): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create([
        'objective_id' => $objective->id,
        'title' => 'Phase 4H handoff evidence',
        'type' => AgenticMarketingOpportunityType::ContentNetwork->value,
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Phase 4H '.Str::lower(Str::random(8)),
            'reasoning' => 'Handoff is operator review output only.',
            'recommendation' => 'Keep default planner legacy.',
            'signals' => ['topic_keyword' => 'Phase 4H'],
        ],
    ]);
}

it('returns handoff_ready for exact scope with Phase 4G rehearsal_ready and acknowledgement', function (): void {
    $report = phase4hService(phase4hRehearsal())->handoff(phase4hInput());

    expect($report['handoff_status'])->toBe(AgenticPlannerDefaultSelectionActivationHandoffService::STATUS_READY)
        ->and($report['phase_4g_rehearsal_status'])->toBe(AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_READY)
        ->and($report['phase_4f_package_checksum'])->toBe(str_repeat('b', 64))
        ->and($report['selected_planner_remains'])->toBe('legacy')
        ->and($report['runtime_behavior_changed'])->toBeFalse()
        ->and(data_get($report, 'operator_handoff_acknowledgement.status'))->toBe(AgenticPlannerDefaultSelectionActivationHandoffService::ACKNOWLEDGED)
        ->and(data_get($report, 'dry_run_activation_plan_summary.activation_performed'))->toBeFalse()
        ->and(data_get($report, 'dry_run_activation_plan_summary.activation_flags_consumed'))->toBeFalse()
        ->and($report['blocked_reasons'])->toBe([]);
});

it('blocks handoff when Phase 4G rehearsal is blocked', function (): void {
    $report = phase4hService(phase4hRehearsal([
        'rehearsal_status' => AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_BLOCKED,
        'blocked_reasons' => ['phase_4f_package_ready_required'],
        'remediation_guidance' => [
            ['reason' => 'phase_4f_package_ready_required', 'guidance' => 'Re-run Phase 4F.'],
        ],
    ]))->handoff(phase4hInput());

    expect($report['handoff_status'])->toBe(AgenticPlannerDefaultSelectionActivationHandoffService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('phase_4f_package_ready_required')
        ->and($report['blocked_reasons'])->toContain('phase_4g_rehearsal_ready_required')
        ->and(collect($report['remediation_guidance'])->pluck('reason')->all())->toContain('phase_4g_rehearsal_ready_required');
});

it('blocks missing exact real scope instead of inferring wildcard scope', function (): void {
    $report = phase4hService(phase4hRehearsal([
        'scope' => [
            'real_scope_detected' => false,
            'explicit_workspace_objective_scope' => false,
        ],
    ]))->handoff(phase4hInput([
        'require_real_scope' => false,
    ]));

    expect($report['handoff_status'])->toBe(AgenticPlannerDefaultSelectionActivationHandoffService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('exact_real_scope_required')
        ->and(data_get($report, 'exact_scope_summary.require_real_scope'))->toBeFalse()
        ->and(data_get($report, 'exact_scope_summary.wildcard_scope_inferred'))->toBeFalse();
});

it('blocks wildcard percentage or global scope from the Phase 4G rehearsal', function (): void {
    $report = phase4hService(phase4hRehearsal([
        'scope' => [
            'wildcard_scope_inferred' => true,
            'percentage_scope_used' => true,
            'global_scope_used' => true,
        ],
    ]))->handoff(phase4hInput());

    expect($report['handoff_status'])->toBe(AgenticPlannerDefaultSelectionActivationHandoffService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('exact_real_scope_required')
        ->and(data_get($report, 'exact_scope_summary.wildcard_scope_inferred'))->toBeTrue()
        ->and(data_get($report, 'exact_scope_summary.percentage_scope_used'))->toBeTrue()
        ->and(data_get($report, 'exact_scope_summary.global_scope_used'))->toBeTrue();
});

it('blocks percentage or global handoff scope even when wildcard scope is not inferred', function (): void {
    $report = phase4hService(phase4hRehearsal([
        'scope' => [
            'wildcard_scope_inferred' => false,
            'percentage_scope_used' => true,
            'global_scope_used' => true,
        ],
    ]))->handoff(phase4hInput());

    expect($report['handoff_status'])->toBe(AgenticPlannerDefaultSelectionActivationHandoffService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('exact_real_scope_required')
        ->and(data_get($report, 'exact_scope_summary.wildcard_scope_inferred'))->toBeFalse()
        ->and(data_get($report, 'exact_scope_summary.percentage_scope_used'))->toBeTrue()
        ->and(data_get($report, 'exact_scope_summary.global_scope_used'))->toBeTrue()
        ->and(collect($report['operator_handoff_checklist'])->firstWhere('item', 'exact_real_scope_confirmed')['ready'])->toBeFalse();
});

it('blocks missing activation handoff acknowledgement', function (): void {
    $report = phase4hService(phase4hRehearsal())->handoff(phase4hInput([
        'ack_activation_handoff' => false,
    ]));

    expect($report['handoff_status'])->toBe(AgenticPlannerDefaultSelectionActivationHandoffService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain(AgenticPlannerDefaultSelectionActivationHandoffService::ACKNOWLEDGEMENT_MISSING)
        ->and(data_get($report, 'operator_handoff_acknowledgement.acknowledged'))->toBeFalse()
        ->and(data_get($report, 'operator_handoff_acknowledgement.activation_performed'))->toBeFalse();
});

it('preserves the Phase 4F package checksum from the Phase 4G rehearsal', function (): void {
    $checksum = hash('sha256', 'phase-4h-preserves-phase-4f-checksum');
    $service = phase4hService(phase4hRehearsal([
        'package_checksum' => $checksum,
    ]));

    $first = $service->handoff(phase4hInput());
    sleep(1);
    $second = $service->handoff(phase4hInput());

    expect($first['phase_4f_package_checksum'])->toBe($checksum)
        ->and($second['phase_4f_package_checksum'])->toBe($checksum)
        ->and($first['phase_4f_package_checksum'])->toBe($second['phase_4f_package_checksum'])
        ->and(data_get($first, 'phase_4g_rehearsal_report.package_checksum'))->toBe($checksum);
});

it('blocks handoff when the Phase 4F package checksum is missing from Phase 4G', function (): void {
    $report = phase4hService(phase4hRehearsal([
        'package_checksum' => null,
    ]))->handoff(phase4hInput());

    expect($report['handoff_status'])->toBe(AgenticPlannerDefaultSelectionActivationHandoffService::STATUS_BLOCKED)
        ->and($report['phase_4f_package_checksum'])->toBeNull()
        ->and($report['blocked_reasons'])->toContain('phase_4f_package_checksum_required')
        ->and(collect($report['operator_handoff_checklist'])->firstWhere('item', 'phase_4f_package_checksum_preserved')['ready'])->toBeFalse();
});

it('blocks handoff when the dry-run activation summary reports activation or flag consumption', function (): void {
    $report = phase4hService(phase4hRehearsal([
        'rehearsal_activation_plan' => [
            'activation_performed' => true,
            'activation_flags_consumed' => true,
            'runtime_behavior_changed' => true,
            'selected_planner_during_rehearsal' => 'canonical',
        ],
    ]))->handoff(phase4hInput());

    expect($report['handoff_status'])->toBe(AgenticPlannerDefaultSelectionActivationHandoffService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('dry_run_activation_plan_must_not_activate')
        ->and(data_get($report, 'dry_run_activation_plan_summary.activation_performed'))->toBeFalse()
        ->and(data_get($report, 'dry_run_activation_plan_summary.activation_flags_consumed'))->toBeFalse();
});

it('keeps rollback handoff legacy-first and does not perform rollback', function (): void {
    $report = phase4hService(phase4hRehearsal())->handoff(phase4hInput());

    expect(data_get($report, 'rollback_rehearsal_summary.passed'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_summary.legacy_planner_output_remains_authoritative'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_summary.legacy_agentic_action_ownership_remains_authoritative'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_summary.future_activation_disable_keeps_legacy_output_selected'))->toBeTrue()
        ->and(data_get($report, 'rollback_rehearsal_summary.rollback_performed'))->toBeFalse()
        ->and(data_get($report, 'rollback_rehearsal_summary.metadata_removal_required_for_rollback'))->toBeFalse()
        ->and(data_get($report, 'rollback_rehearsal_summary.payload_status_dedupe_parent_approval_execution_changes_required'))->toBeFalse()
        ->and(data_get($report, 'legacy_first_confirmation.legacy_planner_output_authoritative'))->toBeTrue()
        ->and(data_get($report, 'legacy_first_confirmation.legacy_agentic_action_ownership_authoritative'))->toBeTrue();
});

it('blocks handoff when rollback rehearsal is not legacy-first', function (): void {
    $report = phase4hService(phase4hRehearsal([
        'rollback_rehearsal_result' => [
            'passed' => false,
            'legacy_planner_output_remains_authoritative' => false,
            'legacy_agentic_action_ownership_remains_authoritative' => false,
            'future_activation_disable_keeps_legacy_output_selected' => false,
            'metadata_removal_required_for_rollback' => true,
            'ownership_migration_required' => true,
            'lifecycle_sync_required' => true,
            'payload_status_dedupe_parent_approval_execution_changes_required' => true,
            'historical_rewrite_required' => true,
            'runtime_audit_write_required' => true,
            'route_or_approval_change_required' => true,
            'job_dispatch_required' => true,
            'rollback_performed' => true,
        ],
    ]))->handoff(phase4hInput());

    expect($report['handoff_status'])->toBe(AgenticPlannerDefaultSelectionActivationHandoffService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('rollback_rehearsal_must_be_legacy_first')
        ->and(data_get($report, 'rollback_rehearsal_summary.rollback_performed'))->toBeFalse()
        ->and(collect($report['operator_handoff_checklist'])->firstWhere('item', 'rollback_rehearsal_legacy_first')['ready'])->toBeFalse();
});

it('does not mutate planner output actions ownership lifecycle payload status dedupe audits routes approvals or jobs', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase4hContext('phase-4h-no-mutations');
    $legacy = phase4hOpportunity($objective);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'title' => 'Existing Phase 4H action',
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

    $report = phase4hService(phase4hRehearsal([
        'workspace_id' => (string) $workspace->id,
        'objective_ids' => [(string) $objective->id],
        'scope' => [
            'workspace_id' => (string) $workspace->id,
            'objective_ids' => [(string) $objective->id],
            'real_scope_detected' => true,
            'explicit_workspace_objective_scope' => true,
        ],
    ]))->handoff(phase4hInput([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
    ]));

    expect($report['handoff_status'])->toBe(AgenticPlannerDefaultSelectionActivationHandoffService::STATUS_READY)
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
    $blocked = phase4hService(phase4hRehearsal([
        'rehearsal_status' => AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_BLOCKED,
        'blocked_reasons' => ['phase_4f_package_ready_required'],
    ]))->handoff(phase4hInput());

    app()->instance(
        AgenticPlannerDefaultSelectionActivationHandoffService::class,
        phase4hCommandService($blocked)
    );

    $arguments = [
        '--workspace' => 'workspace-id',
        '--objectives' => 'objective-id',
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
        '--ack-operator-signoff' => true,
        '--ack-activation-handoff' => true,
        '--require-real-scope' => true,
    ];

    $this->artisan('mos:handoff-agentic-planner-default-selection-activation', $arguments)
        ->assertSuccessful()
        ->expectsOutputToContain('Operator handoff only')
        ->expectsOutputToContain('Handoff status')
        ->expectsOutputToContain('Dry-run activation plan summary')
        ->expectsOutputToContain('Rollback rehearsal summary')
        ->expectsOutputToContain('final handoff status: handoff_blocked');

    $this->artisan('mos:handoff-agentic-planner-default-selection-activation', array_replace($arguments, [
        '--ci' => true,
    ]))
        ->assertExitCode(1)
        ->expectsOutputToContain('final handoff status: handoff_blocked');
});

it('command exits zero for a ready handoff in ci mode', function (): void {
    $ready = phase4hService(phase4hRehearsal())->handoff(phase4hInput());

    app()->instance(
        AgenticPlannerDefaultSelectionActivationHandoffService::class,
        phase4hCommandService($ready)
    );

    $this->artisan('mos:handoff-agentic-planner-default-selection-activation', [
        '--workspace' => 'workspace-id',
        '--objectives' => 'objective-id',
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
        '--ack-operator-signoff' => true,
        '--ack-activation-handoff' => true,
        '--require-real-scope' => true,
        '--ci' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('final handoff status: handoff_ready')
        ->expectsOutputToContain('selected planner remains: legacy')
        ->expectsOutputToContain('runtime_behavior_changed: no');
});

it('includes remediation guidance for rehearsal scope and acknowledgement blockers', function (): void {
    $report = phase4hService(phase4hRehearsal([
        'rehearsal_status' => AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_BLOCKED,
        'blocked_reasons' => ['phase_4d_evidence_ready_required'],
        'scope' => [
            'real_scope_detected' => false,
            'explicit_workspace_objective_scope' => false,
        ],
    ]))->handoff(phase4hInput([
        'ack_activation_handoff' => false,
        'require_real_scope' => false,
    ]));

    $guidance = collect($report['remediation_guidance'])->pluck('guidance', 'reason');

    expect($report['handoff_status'])->toBe(AgenticPlannerDefaultSelectionActivationHandoffService::STATUS_BLOCKED)
        ->and($guidance->keys()->all())->toContain('phase_4d_evidence_ready_required')
        ->and($guidance->keys()->all())->toContain('exact_real_scope_required')
        ->and($guidance->keys()->all())->toContain('phase_4g_rehearsal_ready_required')
        ->and($guidance->keys()->all())->toContain(AgenticPlannerDefaultSelectionActivationHandoffService::ACKNOWLEDGEMENT_MISSING)
        ->and($guidance->get('exact_real_scope_required'))->toContain('--require-real-scope')
        ->and($guidance->get('phase_4g_rehearsal_ready_required'))->toContain('rehearsal_status=rehearsal_ready')
        ->and($guidance->get(AgenticPlannerDefaultSelectionActivationHandoffService::ACKNOWLEDGEMENT_MISSING))->toContain('--ack-activation-handoff');
});
