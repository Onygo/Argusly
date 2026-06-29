<?php

use App\Enums\AgenticMarketingActionType;
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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionRuntimeSwitchContractService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeGuardService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeSwitchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3yContext(string $slug = 'phase-3y'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3Y '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3Y Workspace',
        'display_name' => 'Phase 3Y Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3Y Site',
        'site_url' => 'https://phase-3y.test',
        'base_url' => 'https://phase-3y.test',
        'allowed_domains' => ['phase-3y.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3Y objective '.Str::random(4),
        'goal' => 'Inspect runtime switch skeleton',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3yOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3Y runtime switch skeleton',
        'type' => 'content_network',
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Phase 3Y '.Str::lower(Str::random(8)),
            'reasoning' => 'Runtime switch skeleton is decision-only.',
            'recommendation' => 'Keep default planner legacy.',
            'signals' => ['topic_keyword' => 'Phase 3Y'],
        ],
    ], $overrides));
}

function phase3yContractReport(array $overrides = []): array
{
    return array_replace_recursive([
        'final_status' => AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY,
        'phase_3v_guard_decision' => [
            'allowed' => true,
            'phase_3t_status' => 'ready_for_scoped_expansion',
            'phase_3u_eligibility' => 'eligible',
            'blocked_reasons' => [],
        ],
        'phase_3w_planner_path_diagnostic_state' => [
            'available' => true,
            'summary' => [
                'ok' => true,
                'guard_called' => true,
                'guard_allowed' => true,
                'selected_planner_remains' => 'legacy',
                'blocked_reasons' => [],
            ],
        ],
        'blocked_reasons' => [],
        'runtime_switching_implemented' => false,
        'planner_selection_changed' => false,
    ], $overrides);
}

function phase3yContract(array $report): AgenticPlannerDefaultSelectionRuntimeSwitchContractService
{
    return new class($report) extends AgenticPlannerDefaultSelectionRuntimeSwitchContractService
    {
        public array $receivedInput = [];

        public function __construct(private readonly array $report) {}

        public function report(array $input): array
        {
            $this->receivedInput = $input;

            return $this->report;
        }
    };
}

function phase3ySwitch(array $report): AgenticPlannerDefaultSelectionScopedRuntimeSwitchService
{
    return new AgenticPlannerDefaultSelectionScopedRuntimeSwitchService(phase3yContract($report));
}

function phase3yGuardDecision(bool $allowed = true): AgenticPlannerDefaultSelectionScopedRuntimeGuardService
{
    return new class($allowed) extends AgenticPlannerDefaultSelectionScopedRuntimeGuardService
    {
        public function __construct(private readonly bool $allowed) {}

        public function decide(array $input): array
        {
            return [
                'allowed' => $this->allowed,
                'mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::MODE,
                'workspace_id' => (string) ($input['workspace'] ?? 'workspace-id'),
                'objective_ids' => (array) ($input['objectives'] ?? []),
                'blocked_reasons' => $this->allowed ? [] : ['phase_3y_test_guard_blocked'],
                'rollback_mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
                'phase_3t_status' => $this->allowed ? 'ready_for_scoped_expansion' : 'blocked',
                'phase_3u_eligibility' => $this->allowed ? 'eligible' : 'blocked',
            ];
        }
    };
}

function phase3yAllowSwitchScope(Workspace $workspace, array $objectives, bool $runtimeSwitchAck = false): void
{
    config()->set('mos.agentic_planner.default_selection.switch_allowed_scopes', [[
        'workspace_id' => (string) $workspace->id,
        'objective_ids' => collect($objectives)->map(fn (AgenticMarketingObjective $objective): string => (string) $objective->id)->values()->all(),
        'runtime_switch_contract_acknowledged' => $runtimeSwitchAck,
    ]]);
}

function phase3yEnableAllSwitchConfig(Workspace $workspace, AgenticMarketingObjective $objective, bool $scopeAck = false): void
{
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', true);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    phase3yAllowSwitchScope($workspace, [$objective], $scopeAck);
}

it('keeps Phase 3Y switch config disabled scoped exact only and without global percentage or wildcard defaults', function (): void {
    $config = (array) config('mos.agentic_planner.default_selection');
    $configSource = file_get_contents(config_path('mos.php')) ?: '';

    expect($config['scoped_runtime_switch_enabled'] ?? null)->toBeFalse()
        ->and($config['switch_allowed_scopes'] ?? null)->toBe([])
        ->and($config)->not->toHaveKey('global_runtime_switch_enabled')
        ->and($config)->not->toHaveKey('global_rollout_enabled')
        ->and($config)->not->toHaveKey('rollout_percentage')
        ->and($config)->not->toHaveKey('percentage_rollout')
        ->and($configSource)->not->toContain("'*'")
        ->and($configSource)->not->toContain('"*"');
});

it('blocks when the switch flag is false', function (): void {
    [, $workspace, , $objective] = phase3yContext('phase-3y-switch-flag');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', false);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    phase3yAllowSwitchScope($workspace, [$objective], true);

    $decision = phase3ySwitch(phase3yContractReport())->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($decision['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED)
        ->and($decision['blocked_reasons'])->toContain('scoped_runtime_switch_feature_flag_disabled')
        ->and($decision['selected_planner'])->toBe('legacy')
        ->and($decision['planner_output_changed'])->toBeFalse();
});

it('blocks when the scope is not explicitly switch allowed', function (): void {
    [, $workspace, , $objective] = phase3yContext('phase-3y-scope');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', true);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    config()->set('mos.agentic_planner.default_selection.switch_allowed_scopes', []);

    $decision = phase3ySwitch(phase3yContractReport())->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
        'ack_runtime_switch_contract' => true,
    ]);

    expect($decision['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED)
        ->and($decision['blocked_reasons'])->toContain('workspace_objective_scope_not_explicitly_switch_allowed')
        ->and($decision['allowed_scope_status']['explicitly_allowed'])->toBeFalse();
});

it('does not allow wildcard switch scopes', function (): void {
    [, $workspace, , $objective] = phase3yContext('phase-3y-wildcard');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', true);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    config()->set('mos.agentic_planner.default_selection.switch_allowed_scopes', [[
        'workspace_id' => '*',
        'objective_ids' => ['*'],
        'runtime_switch_contract_acknowledged' => true,
    ]]);

    $decision = phase3ySwitch(phase3yContractReport())->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
        'ack_runtime_switch_contract' => true,
    ]);

    expect($decision['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED)
        ->and($decision['blocked_reasons'])->toContain('workspace_objective_scope_not_explicitly_switch_allowed');
});

it('blocks when Phase 3X is not contract_ready', function (): void {
    [, $workspace, , $objective] = phase3yContext('phase-3y-3x');
    phase3yEnableAllSwitchConfig($workspace, $objective, true);

    $decision = phase3ySwitch(phase3yContractReport([
        'final_status' => AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_BLOCKED,
        'blocked_reasons' => ['phase_3w_diagnostics_missing'],
    ]))->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($decision['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED)
        ->and($decision['blocked_reasons'])->toContain('phase_3x_contract_not_ready:'.AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_BLOCKED);
});

it('blocks when Phase 3V guard is not allowed', function (): void {
    [, $workspace, , $objective] = phase3yContext('phase-3y-3v');
    phase3yEnableAllSwitchConfig($workspace, $objective, true);

    $decision = phase3ySwitch(phase3yContractReport([
        'phase_3v_guard_decision' => [
            'allowed' => false,
            'phase_3t_status' => 'blocked_by_preview',
            'phase_3u_eligibility' => 'blocked',
        ],
    ]))->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($decision['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED)
        ->and($decision['blocked_reasons'])->toContain('phase_3v_guard_not_allowed');
});

it('blocks when Phase 3W legacy diagnostic is missing or non legacy', function (array $summary, string $reason): void {
    [, $workspace, , $objective] = phase3yContext('phase-3y-3w-'.$reason);
    phase3yEnableAllSwitchConfig($workspace, $objective, true);

    $decision = phase3ySwitch(phase3yContractReport([
        'phase_3w_planner_path_diagnostic_state' => [
            'available' => $summary !== [],
            'summary' => $summary,
        ],
    ]))->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($decision['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED)
        ->and($decision['blocked_reasons'])->toContain($reason);
})->with([
    'missing legacy diagnostic' => [['selected_planner_remains' => null], 'phase_3w_legacy_diagnostic_missing'],
    'canonical planner diagnostic' => [['selected_planner_remains' => 'canonical'], 'phase_3w_selected_planner_not_legacy:canonical'],
]);

it('blocks when the Phase 3V runtime guard flag is disabled', function (): void {
    [, $workspace, , $objective] = phase3yContext('phase-3y-runtime-guard-flag');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', true);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', false);
    phase3yAllowSwitchScope($workspace, [$objective], true);

    $decision = phase3ySwitch(phase3yContractReport())->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($decision['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED)
        ->and($decision['blocked_reasons'])->toContain('scoped_runtime_guard_feature_flag_disabled');
});

it('returns switch_ready only when all gates pass and selected planner remains legacy', function (): void {
    [, $workspace, $site, $objective] = phase3yContext('phase-3y-ready');
    phase3yEnableAllSwitchConfig($workspace, $objective);

    $contract = phase3yContract(phase3yContractReport());
    $decision = (new AgenticPlannerDefaultSelectionScopedRuntimeSwitchService($contract))->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'site' => (string) $site->id,
        'detector' => 'content_network_gaps',
        'limit' => 3,
        'ack_metadata_only_review' => true,
        'ack_runtime_switch_contract' => true,
    ]);

    expect($decision['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY)
        ->and($decision['blocked_reasons'])->toBe([])
        ->and($decision['selected_planner'])->toBe('legacy')
        ->and($decision['selected_action_ownership_mode'])->toBe('legacy_owned')
        ->and($decision['runtime_switching_implemented'])->toBeFalse()
        ->and($decision['planner_selection_changed'])->toBeFalse()
        ->and($decision['planner_output_changed'])->toBeFalse()
        ->and($contract->receivedInput)->toBe([
            'workspace' => (string) $workspace->id,
            'objectives' => [(string) $objective->id],
            'site' => (string) $site->id,
            'detector' => 'content_network_gaps',
            'limit' => 3,
            'ack_metadata_only_review' => true,
        ]);
});

it('default command execution does not write audit rows', function (): void {
    [, $workspace, , $objective] = phase3yContext('phase-3y-command-read-only');
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::class, phase3ySwitch(phase3yContractReport()));

    $this->artisan('mos:inspect-agentic-planner-default-selection-scoped-runtime-switch', [
        '--workspace' => (string) $workspace->id,
        '--objectives' => (string) $objective->id,
        '--limit' => 1,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Phase 3Y Agentic planner default-selection scoped runtime switch skeleton.')
        ->expectsOutputToContain('audit snapshot written: no');

    expect(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe(0);
});

it('--write-audit-snapshot writes exactly one audit row', function (): void {
    [, $workspace, , $objective] = phase3yContext('phase-3y-command-write');
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::class, phase3ySwitch(phase3yContractReport()));

    $this->artisan('mos:inspect-agentic-planner-default-selection-scoped-runtime-switch', [
        '--workspace' => (string) $workspace->id,
        '--objectives' => (string) $objective->id,
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
        '--write-audit-snapshot' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('audit snapshot written: yes');

    $audit = AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->sole();

    expect($audit->workspace_id)->toBe((string) $workspace->id)
        ->and($audit->objective_ids)->toBe([(string) $objective->id])
        ->and($audit->phase_3t_status)->toBe('ready_for_scoped_expansion')
        ->and($audit->phase_3u_eligibility)->toBe('eligible')
        ->and($audit->phase_3v_guard_allowed)->toBeTrue()
        ->and($audit->phase_3w_selected_planner_remains)->toBe('legacy')
        ->and($audit->phase_3x_contract_status)->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY)
        ->and($audit->switch_decision)->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED)
        ->and($audit->selected_planner)->toBe('legacy')
        ->and($audit->selected_action_ownership_mode)->toBe('legacy_owned')
        ->and($audit->payload_namespace)->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_NAMESPACE)
        ->and($audit->payload_version)->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_VERSION)
        ->and($audit->created_at)->not->toBeNull()
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe(1);
});

it('--write-audit-snapshot writes only the audit row and does not mutate planner state', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase3yContext('phase-3y-command-write-no-mutations');
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::class, phase3ySwitch(phase3yContractReport()));

    $legacy = phase3yOpportunity($objective, ['status' => 'closed']);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'title' => 'Existing Phase 3Y command action',
            'planning' => ['planner' => 'legacy-planner'],
        ],
    ]);
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
    ];

    $this->artisan('mos:inspect-agentic-planner-default-selection-scoped-runtime-switch', [
        '--workspace' => (string) $workspace->id,
        '--objectives' => (string) $objective->id,
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
        '--write-audit-snapshot' => true,
    ])->assertSuccessful();

    expect($legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']))->toBe($before['legacy'])
        ->and($action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']))->toBe($before['action'])
        ->and(AgenticMarketingAction::query()->count())->toBe($before['actions'])
        ->and(AgenticMarketingOpportunity::query()->count())->toBe($before['opportunities'])
        ->and(AgenticMarketingRun::query()->count())->toBe($before['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($before['run_items'])
        ->and(AgenticActionRun::query()->count())->toBe($before['action_runs'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($before['audit_logs'])
        ->and(Opportunity::query()->count())->toBe($before['canonical'])
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe($before['runtime_switch_audits'] + 1);

    Bus::assertNothingDispatched();
});

it('planner runtime does not write Phase 3Y audit rows or change the legacy planner output', function (): void {
    [, , , $objective] = phase3yContext('phase-3y-planner-runtime-no-audit');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', true);
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeGuardService::class, phase3yGuardDecision(true));

    phase3yOpportunity($objective);

    $summary = app(AgenticMarketingActionPlanner::class)->planForObjective($objective);

    expect($summary['created'])->toBe(1)
        ->and(AgenticMarketingAction::query()->pluck('opportunity_id')->unique()->values()->all())->toHaveCount(1)
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe(0);
});

it('does not create actions migrate ownership sync lifecycle mutate payload status dedupe or dispatch jobs', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase3yContext('phase-3y-no-mutations');
    phase3yEnableAllSwitchConfig($workspace, $objective, true);

    $legacy = phase3yOpportunity($objective, ['status' => 'closed']);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'title' => 'Existing Phase 3Y action',
            'planning' => ['planner' => 'legacy-planner'],
        ],
    ]);
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

    $decision = phase3ySwitch(phase3yContractReport())->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'limit' => 1,
    ]);

    expect($decision['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY)
        ->and($decision['selected_planner'])->toBe('legacy')
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
