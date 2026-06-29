<?php

use App\Enums\AgenticMarketingActionType;
use App\Models\AgenticActionRun;
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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionRuntimeSwitchContractService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3xContext(string $slug = 'phase-3x'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3X '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3X Workspace',
        'display_name' => 'Phase 3X Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3X Site',
        'site_url' => 'https://phase-3x.test',
        'base_url' => 'https://phase-3x.test',
        'allowed_domains' => ['phase-3x.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3X objective '.Str::random(4),
        'goal' => 'Define contract-only runtime switch rules',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3xOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3X runtime switch contract',
        'type' => 'content_network',
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Phase 3X '.Str::lower(Str::random(8)),
            'reasoning' => 'Runtime switching contract is read-only.',
            'recommendation' => 'Keep default selection legacy.',
            'signals' => ['topic_keyword' => 'Phase 3X'],
        ],
    ], $overrides));
}

function phase3xGuardDecision(bool $allowed, array $blockedReasons = []): array
{
    return [
        'allowed' => $allowed,
        'mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::MODE,
        'workspace_id' => 'workspace-id',
        'objective_ids' => ['objective-id'],
        'blocked_reasons' => $blockedReasons,
        'rollback_mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
        'phase_3t_status' => $allowed ? 'ready_for_scoped_expansion' : 'blocked',
        'phase_3u_eligibility' => $allowed ? 'eligible' : 'blocked',
    ];
}

function phase3xGuard(array $decision): AgenticPlannerDefaultSelectionScopedRuntimeGuardService
{
    return new class($decision) extends AgenticPlannerDefaultSelectionScopedRuntimeGuardService
    {
        public array $receivedInput = [];

        public function __construct(private readonly array $decision) {}

        public function decide(array $input): array
        {
            $this->receivedInput = $input;

            return $this->decision;
        }
    };
}

function phase3xContract(AgenticPlannerDefaultSelectionScopedRuntimeGuardService $guard): AgenticPlannerDefaultSelectionRuntimeSwitchContractService
{
    return new AgenticPlannerDefaultSelectionRuntimeSwitchContractService($guard);
}

function phase3xDiagnostics(bool $guardAllowed = true, string $selectedPlanner = 'legacy', bool $ok = true, bool $guardCalled = true): array
{
    return [
        'ok' => $ok,
        'guard_called' => $guardCalled,
        'guard_allowed' => $guardAllowed,
        'blocked_reasons' => $guardAllowed ? [] : ['phase_3v_guard_blocked'],
        'rollback_mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
        'requested_scope' => [
            'workspace_id' => 'workspace-id',
            'objective_ids' => ['objective-id'],
            'site_id' => 'site-id',
        ],
        'runtime_activation_statement' => 'Diagnostic hook only. Default selection remains legacy.',
        'selected_planner_remains' => $selectedPlanner,
    ];
}

it('returns contract_blocked when the Phase 3V guard is blocked', function (): void {
    [, $workspace, $site, $objective] = phase3xContext('phase-3x-guard-blocked');
    app()->instance(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::DIAGNOSTICS_KEY, phase3xDiagnostics());

    $report = phase3xContract(phase3xGuard(phase3xGuardDecision(false, ['phase_3t_status_not_ready_for_scoped_expansion:blocked'])))->report([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'site' => (string) $site->id,
        'limit' => 1,
        'ack_metadata_only_review' => true,
    ]);

    expect($report['final_status'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('phase_3v_guard_blocked')
        ->and($report['runtime_switching_implemented'])->toBeFalse()
        ->and($report['planner_selection_changed'])->toBeFalse();
});

it('returns contract_blocked when Phase 3W diagnostics are missing', function (): void {
    [, $workspace, , $objective] = phase3xContext('phase-3x-missing-3w');

    $report = phase3xContract(phase3xGuard(phase3xGuardDecision(true)))->report([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'limit' => 1,
    ]);

    expect($report['final_status'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('phase_3w_diagnostics_missing')
        ->and($report['phase_3w_planner_path_diagnostic_state']['available'])->toBeFalse();
});

it('returns contract_blocked when Phase 3W diagnostics indicate non legacy behavior', function (): void {
    [, $workspace, , $objective] = phase3xContext('phase-3x-non-legacy');
    app()->instance(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::DIAGNOSTICS_KEY, phase3xDiagnostics(selectedPlanner: 'canonical'));

    $report = phase3xContract(phase3xGuard(phase3xGuardDecision(true)))->report([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'limit' => 1,
    ]);

    expect($report['final_status'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('phase_3w_selected_planner_not_legacy')
        ->and(data_get($report, 'phase_3w_planner_path_diagnostic_state.summary.selected_planner_remains'))->toBe('canonical');
});

it('returns contract_blocked when Phase 3W diagnostics did not call or allow the guard', function (array $diagnostics, string $reason): void {
    [, $workspace, , $objective] = phase3xContext('phase-3x-3w-required-'.$reason);
    app()->instance(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::DIAGNOSTICS_KEY, $diagnostics);

    $report = phase3xContract(phase3xGuard(phase3xGuardDecision(true)))->report([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'limit' => 1,
    ]);

    expect($report['final_status'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain($reason);
})->with([
    'guard not called' => [phase3xDiagnostics(guardCalled: false), 'phase_3w_guard_not_called'],
    'guard not allowed' => [phase3xDiagnostics(guardAllowed: false), 'phase_3w_guard_not_allowed'],
    'diagnostics failed' => [phase3xDiagnostics(ok: false), 'phase_3w_diagnostics_failed'],
]);

it('returns contract_ready only when guard allowed diagnostics exist and selected planner remains legacy', function (): void {
    [, $workspace, $site, $objective] = phase3xContext('phase-3x-ready');
    app()->instance(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::DIAGNOSTICS_KEY, phase3xDiagnostics());
    $guard = phase3xGuard(phase3xGuardDecision(true));

    $report = phase3xContract($guard)->report([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'site' => (string) $site->id,
        'detector' => 'content_network_gaps',
        'limit' => 3,
        'ack_metadata_only_review' => true,
    ]);

    expect($report['final_status'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY)
        ->and($report['blocked_reasons'])->toBe([])
        ->and($report['proposed_future_switch_mode'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchContractService::MODE)
        ->and(data_get($report, 'required_separate_switch_flag.name'))->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchContractService::REQUIRED_SWITCH_FLAG)
        ->and(data_get($report, 'required_separate_switch_flag.default_enabled'))->toBeFalse()
        ->and($guard->receivedInput)->toBe([
            'workspace' => (string) $workspace->id,
            'objectives' => [(string) $objective->id],
            'site' => (string) $site->id,
            'detector' => 'content_network_gaps',
            'limit' => 3,
            'ack_metadata_only_review' => true,
        ]);
});

it('includes all required contracts and forbidden mutations', function (): void {
    [, $workspace, , $objective] = phase3xContext('phase-3x-contracts');
    app()->instance(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::DIAGNOSTICS_KEY, phase3xDiagnostics());

    $report = phase3xContract(phase3xGuard(phase3xGuardDecision(true)))->report([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'limit' => 1,
    ]);

    expect($report)->toHaveKeys([
        'ownership_contract',
        'action_creation_contract',
        'lifecycle_contract',
        'audit_contract',
        'rollback_contract',
        'dedupe_contract',
        'payload_contract',
        'dispatch_contract',
    ])
        ->and($report['ownership_contract'])->toContain('legacy AgenticMarketingOpportunity ownership remains rollback authority')
        ->and($report['ownership_contract'])->toContain('no AgenticMarketingAction.opportunity_id rewrite')
        ->and($report['action_creation_contract'])->toContain('no canonical action creation unless future switch flag is enabled and guard is allowed')
        ->and($report['action_creation_contract'])->toContain('canonical-created actions must be explicitly distinguishable from legacy-created actions')
        ->and($report['action_creation_contract'])->toContain('no duplicate open legacy actions may exist')
        ->and($report['lifecycle_contract'])->toContain('no lifecycle sync until lifecycle ambiguity/conflict remains zero')
        ->and($report['audit_contract'])->toContain('audit must include guard decision')
        ->and($report['audit_contract'])->toContain('audit must include Phase 3T status')
        ->and($report['audit_contract'])->toContain('audit must include Phase 3U eligibility')
        ->and($report['audit_contract'])->toContain('audit must include Phase 3W diagnostic summary')
        ->and($report['audit_contract'])->toContain('audit must include selected action ownership mode')
        ->and($report['rollback_contract'])->toContain('disabling the future switch flag must return behavior to legacy selection without migration')
        ->and($report['dedupe_contract'])->toContain('duplicate open legacy action risk must be zero')
        ->and($report['payload_contract'])->toContain('future switch payload additions must be additive and namespaced')
        ->and($report['dispatch_contract'])->toContain('no job dispatch during contract phase')
        ->and($report['forbidden_mutations'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchContractService::FORBIDDEN_MUTATIONS);
});

it('does not create actions migrate ownership mutate payload status dedupe sync lifecycle or dispatch jobs', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase3xContext('phase-3x-read-only');
    $legacy = phase3xOpportunity($objective, ['status' => 'closed']);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => ['title' => 'Existing Phase 3X action'],
    ]);
    app()->instance(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::DIAGNOSTICS_KEY, phase3xDiagnostics());
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
    ];

    $report = phase3xContract(phase3xGuard(phase3xGuardDecision(true)))->report([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'limit' => 1,
    ]);

    expect($report['final_status'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY)
        ->and($legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']))->toBe($before['legacy'])
        ->and($action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']))->toBe($before['action'])
        ->and(AgenticMarketingAction::query()->count())->toBe($before['actions'])
        ->and(AgenticMarketingOpportunity::query()->count())->toBe($before['opportunities'])
        ->and(AgenticMarketingRun::query()->count())->toBe($before['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($before['run_items'])
        ->and(AgenticActionRun::query()->count())->toBe($before['action_runs'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($before['audit_logs'])
        ->and(Opportunity::query()->count())->toBe($before['canonical']);

    Bus::assertNothingDispatched();
});

it('keeps the Phase 3Y runtime switch flag disabled when registered in config', function (): void {
    $config = (array) config('mos.agentic_planner.default_selection');
    $configSource = file_get_contents(config_path('mos.php')) ?: '';

    expect($config['scoped_runtime_switch_enabled'] ?? null)->toBeFalse()
        ->and($config['switch_allowed_scopes'] ?? null)->toBe([])
        ->and($configSource)->toContain('MOS_AGENTIC_PLANNER_DEFAULT_SELECTION_SCOPED_RUNTIME_SWITCH_ENABLED')
        ->and($configSource)->toContain('scoped_runtime_switch_enabled')
        ->and($config)->not->toHaveKey('rollout_percentage')
        ->and($config)->not->toHaveKey('percentage_rollout')
        ->and($config)->not->toHaveKey('global_runtime_switch_enabled');
});

it('prints the Phase 3X contract inspection report while the Phase 3Y switch flag remains disabled', function (): void {
    [, $workspace, , $objective] = phase3xContext('phase-3x-command');
    app()->instance(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::DIAGNOSTICS_KEY, phase3xDiagnostics());
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeGuardService::class, phase3xGuard(phase3xGuardDecision(true)));

    $this->artisan('mos:inspect-agentic-planner-default-selection-runtime-switch-contract', [
        '--workspace' => (string) $workspace->id,
        '--objectives' => (string) $objective->id,
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Design-only Phase 3X Agentic planner default-selection runtime switch contract.')
        ->expectsOutputToContain('required separate switch flag: '.AgenticPlannerDefaultSelectionRuntimeSwitchContractService::REQUIRED_SWITCH_FLAG)
        ->expectsOutputToContain('future switch flag default enabled: no')
        ->expectsOutputToContain('Phase 3V guard decision: allowed')
        ->expectsOutputToContain('Phase 3W diagnostics available: yes')
        ->expectsOutputToContain('Phase 3W diagnostics ok: yes')
        ->expectsOutputToContain('Phase 3W guard called: yes')
        ->expectsOutputToContain('Phase 3W guard allowed: yes')
        ->expectsOutputToContain('Phase 3W selected planner remains: legacy')
        ->expectsOutputToContain('runtime switching implemented: no')
        ->expectsOutputToContain('planner selection changed: no')
        ->expectsOutputToContain('production data altered: no')
        ->expectsOutputToContain('Forbidden mutations:')
        ->expectsOutputToContain('contract_ready');

    expect(config('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled'))->toBeFalse();
});

it('runs the Phase 3X command without mutating DB-backed planner or action fields', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase3xContext('phase-3x-command-read-only');
    $legacy = phase3xOpportunity($objective, ['status' => 'closed']);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'title' => 'Existing Phase 3X command action',
            'planning' => ['planner' => 'legacy-planner'],
        ],
    ]);
    app()->instance(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::DIAGNOSTICS_KEY, phase3xDiagnostics());
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeGuardService::class, phase3xGuard(phase3xGuardDecision(true)));
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
    ];

    $this->artisan('mos:inspect-agentic-planner-default-selection-runtime-switch-contract', [
        '--workspace' => (string) $workspace->id,
        '--objectives' => (string) $objective->id,
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
    ])->assertSuccessful();

    expect($legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']))->toBe($before['legacy'])
        ->and($action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']))->toBe($before['action'])
        ->and(AgenticMarketingAction::query()->count())->toBe($before['actions'])
        ->and(AgenticMarketingOpportunity::query()->count())->toBe($before['opportunities'])
        ->and(AgenticMarketingRun::query()->count())->toBe($before['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($before['run_items'])
        ->and(AgenticActionRun::query()->count())->toBe($before['action_runs'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($before['audit_logs'])
        ->and(Opportunity::query()->count())->toBe($before['canonical']);

    Bus::assertNothingDispatched();
});
