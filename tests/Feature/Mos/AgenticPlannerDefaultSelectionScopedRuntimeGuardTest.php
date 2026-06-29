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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionRolloutReadinessService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRolloutPlanService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3vContext(string $slug = 'phase-3v'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3V '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3V Workspace',
        'display_name' => 'Phase 3V Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3V Site',
        'site_url' => 'https://phase-3v.test',
        'base_url' => 'https://phase-3v.test',
        'allowed_domains' => ['phase-3v.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = phase3vObjective($workspace, $site, $organization);

    return [$organization, $workspace, $site, $objective];
}

function phase3vObjective(Workspace $workspace, ClientSite $site, Organization $organization): AgenticMarketingObjective
{
    return AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3V objective '.Str::random(4),
        'goal' => 'Guard scoped default-selection runtime',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);
}

function phase3vOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3V scoped runtime guard',
        'type' => 'content_network',
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Phase 3V '.Str::lower(Str::random(8)),
            'reasoning' => 'Scoped runtime guard is read-only.',
            'recommendation' => 'Keep legacy-first rollback.',
            'signals' => ['topic_keyword' => 'Phase 3V'],
        ],
    ], $overrides));
}

function phase3vReadinessReport(Workspace $workspace, array $objectives, string $status, array $overrides = []): array
{
    $rows = collect($objectives)
        ->map(fn (AgenticMarketingObjective $objective): array => [
            'objective_id' => (string) $objective->id,
            'workspace_id' => (string) $workspace->id,
            'site_id' => (string) $objective->client_site_id,
            'rollout_readiness_status' => $status,
            'blocked_reasons' => $status === AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION ? [] : ['phase_3t_blocked'],
            'candidate_action_count' => 1,
            'metadata_only_ok_count' => 0,
            'duplicate_open_action_risk_count' => 0,
            'canonical_coverage_sufficient' => true,
            'canonical_legacy_order_exact_match' => true,
            'phase_3i_continuity_status' => 'no_blockers',
            'phase_3j_lifecycle_status' => 'no_ambiguity_or_conflict',
            'metadata_only_action_ownership_approved' => false,
        ])
        ->values()
        ->all();

    return array_replace_recursive([
        'workspace_id' => (string) $workspace->id,
        'site_id' => null,
        'detector_key' => null,
        'limit_per_objective' => 1,
        'rollout_readiness_status' => $status,
        'summary' => [
            'inspected_objective_count' => count($rows),
            'ready_objective_count' => $status === AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION ? count($rows) : 0,
            'blocked_objective_count' => $status === AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION ? 0 : count($rows),
            'metadata_only_ok_count' => 0,
        ],
        'objective_rows' => $rows,
        'recommendation' => $status === AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION
            ? 'eligible for limited multi-objective Phase 3U'
            : 'blocked',
    ], $overrides);
}

function phase3vRolloutPlan(Workspace $workspace, array $objectives, string $eligibility, array $overrides = []): array
{
    $objectiveIds = collect($objectives)
        ->map(fn (AgenticMarketingObjective $objective): string => (string) $objective->id)
        ->values()
        ->all();

    return array_replace_recursive([
        'phase' => '3U',
        'workspace_id' => (string) $workspace->id,
        'requested_objectives' => $objectiveIds,
        'inspected_objectives' => $objectiveIds,
        'readiness_status' => AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION,
        'rollout_eligibility' => $eligibility,
        'blocked_reasons' => $eligibility === AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE ? [] : ['phase_3u_blocked'],
        'recommended_rollout_mode' => AgenticPlannerDefaultSelectionScopedRolloutPlanService::ROLLOUT_MODE,
        'metadata_only_ok_review_requirement' => [
            'manual_review_required' => false,
            'metadata_only_ok_count' => 0,
            'ownership_migration_approved' => false,
        ],
        'order_parity_confirmation' => ['confirmed' => true],
        'duplicate_risk_confirmation' => ['confirmed' => true],
        'runtime_activation_enabled' => false,
    ], $overrides);
}

function phase3vReadinessService(array $report): AgenticPlannerDefaultSelectionRolloutReadinessService
{
    return new class($report) extends AgenticPlannerDefaultSelectionRolloutReadinessService
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

function phase3vPlanService(array $plan): AgenticPlannerDefaultSelectionScopedRolloutPlanService
{
    return new class($plan) extends AgenticPlannerDefaultSelectionScopedRolloutPlanService
    {
        public array $receivedInput = [];

        public function __construct(private readonly array $plan) {}

        public function plan(array $input): array
        {
            $this->receivedInput = $input;

            return $this->plan;
        }
    };
}

function phase3vGuard(array $phase3t, array $phase3u): AgenticPlannerDefaultSelectionScopedRuntimeGuardService
{
    return new AgenticPlannerDefaultSelectionScopedRuntimeGuardService(
        phase3vReadinessService($phase3t),
        phase3vPlanService($phase3u),
    );
}

function phase3vAllowScope(Workspace $workspace, array $objectives, bool $metadataAck = false): void
{
    config()->set('mos.agentic_planner.default_selection.allowed_scopes', [[
        'workspace_id' => (string) $workspace->id,
        'objective_ids' => collect($objectives)->map(fn (AgenticMarketingObjective $objective): string => (string) $objective->id)->values()->all(),
        'metadata_only_ok_review_acknowledged' => $metadataAck,
    ]]);
}

it('keeps scoped runtime config disabled scoped only and without rollout percentages by default', function (): void {
    $config = (array) config('mos.agentic_planner.default_selection');

    expect($config['scoped_runtime_enabled'] ?? null)->toBeFalse()
        ->and($config['allowed_scopes'] ?? null)->toBe([])
        ->and($config)->not->toHaveKey('global_rollout_enabled')
        ->and($config)->not->toHaveKey('rollout_percentage')
        ->and($config)->not->toHaveKey('percentage_rollout');
});

it('composes fresh Phase 3T readiness and Phase 3U rollout plan for the requested scope', function (): void {
    [, $workspace, $site, $objective] = phase3vContext('phase-3v-fresh-gates');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', false);
    phase3vAllowScope($workspace, [$objective], true);
    $phase3tService = phase3vReadinessService(
        phase3vReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION),
    );
    $phase3uService = phase3vPlanService(
        phase3vRolloutPlan($workspace, [$objective], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE),
    );
    $guard = new AgenticPlannerDefaultSelectionScopedRuntimeGuardService($phase3tService, $phase3uService);

    $guard->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'site' => (string) $site->id,
        'detector' => 'content_network_gaps',
        'limit' => 3,
    ]);

    expect($phase3tService->receivedInput)->toBe([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'site' => (string) $site->id,
        'detector' => 'content_network_gaps',
        'limit' => 3,
        'include_metadata_only_ok' => true,
    ])->and($phase3uService->receivedInput)->toBe([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'site' => (string) $site->id,
        'detector' => 'content_network_gaps',
        'limit' => 3,
        'include_metadata_only_ok' => true,
    ]);
});

it('blocks when the scoped runtime feature flag is false', function (): void {
    [, $workspace, , $objective] = phase3vContext('phase-3v-flag-disabled');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', false);
    phase3vAllowScope($workspace, [$objective], true);

    $decision = phase3vGuard(
        phase3vReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION),
        phase3vRolloutPlan($workspace, [$objective], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE),
    )->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($decision['allowed'])->toBeFalse()
        ->and($decision['blocked_reasons'])->toContain('scoped_runtime_feature_flag_disabled')
        ->and($decision['rollback_mode'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE);
});

it('blocks when the workspace objective pair is not explicitly allowed', function (): void {
    [, $workspace, , $objective] = phase3vContext('phase-3v-scope-blocked');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    config()->set('mos.agentic_planner.default_selection.allowed_scopes', []);

    $decision = phase3vGuard(
        phase3vReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION),
        phase3vRolloutPlan($workspace, [$objective], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE),
    )->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
        'ack_metadata_only_review' => true,
    ]);

    expect($decision['allowed'])->toBeFalse()
        ->and($decision['blocked_reasons'])->toContain('workspace_objective_scope_not_explicitly_allowed')
        ->and($decision['allowed_scope_status']['explicitly_allowed'])->toBeFalse();
});

it('fails closed when workspace or objective scope is missing', function (): void {
    [, $workspace, , $objective] = phase3vContext('phase-3v-missing-scope');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    phase3vAllowScope($workspace, [$objective], true);

    $missingWorkspace = phase3vGuard(
        phase3vReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION),
        phase3vRolloutPlan($workspace, [$objective], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE),
    )->decide([
        'workspace' => null,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    $missingObjectives = phase3vGuard(
        phase3vReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION),
        phase3vRolloutPlan($workspace, [$objective], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE),
    )->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => [],
        'limit' => 1,
    ]);

    expect($missingWorkspace['allowed'])->toBeFalse()
        ->and($missingWorkspace['blocked_reasons'])->toContain('scoped_runtime_requires_explicit_workspace_scope')
        ->and($missingWorkspace['allowed_scope_status']['explicitly_allowed'])->toBeFalse()
        ->and($missingObjectives['allowed'])->toBeFalse()
        ->and($missingObjectives['blocked_reasons'])->toContain('scoped_runtime_requires_explicit_objective_scope')
        ->and($missingObjectives['allowed_scope_status']['explicitly_allowed'])->toBeFalse();
});

it('blocks when Phase 3T is not ready', function (): void {
    [, $workspace, , $objective] = phase3vContext('phase-3v-3t-blocked');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    phase3vAllowScope($workspace, [$objective], true);

    $decision = phase3vGuard(
        phase3vReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_PREVIEW),
        phase3vRolloutPlan($workspace, [$objective], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE),
    )->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($decision['allowed'])->toBeFalse()
        ->and($decision['blocked_reasons'])->toContain('phase_3t_status_not_ready_for_scoped_expansion:'.AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_PREVIEW);
});

it('blocks when Phase 3U is not eligible', function (): void {
    [, $workspace, , $objective] = phase3vContext('phase-3v-3u-blocked');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    phase3vAllowScope($workspace, [$objective], true);

    $decision = phase3vGuard(
        phase3vReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION),
        phase3vRolloutPlan($workspace, [$objective], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_BLOCKED),
    )->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($decision['allowed'])->toBeFalse()
        ->and($decision['blocked_reasons'])->toContain('phase_3u_plan_not_eligible:'.AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_BLOCKED);
});

it('blocks when Phase 3T or Phase 3U returned scope mismatches the request', function (): void {
    [$organization, $workspace, $site, $objectiveA] = phase3vContext('phase-3v-scope-mismatch');
    $objectiveB = phase3vObjective($workspace, $site, $organization);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    phase3vAllowScope($workspace, [$objectiveA], true);

    $decision = phase3vGuard(
        phase3vReadinessReport($workspace, [$objectiveB], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION),
        phase3vRolloutPlan($workspace, [$objectiveA], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE, [
            'workspace_id' => 'different-workspace',
        ]),
    )->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objectiveA->id,
        'limit' => 1,
    ]);

    expect($decision['allowed'])->toBeFalse()
        ->and($decision['blocked_reasons'])->toContain('phase_3t_objective_scope_mismatch')
        ->and($decision['blocked_reasons'])->toContain('phase_3u_workspace_scope_mismatch:different-workspace');
});

it('blocks when Phase 3T workspace or Phase 3U objective scope mismatches the request', function (): void {
    [$organization, $workspace, $site, $objectiveA] = phase3vContext('phase-3v-expanded-scope-mismatch');
    $objectiveB = phase3vObjective($workspace, $site, $organization);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    phase3vAllowScope($workspace, [$objectiveA], true);

    $decision = phase3vGuard(
        phase3vReadinessReport($workspace, [$objectiveA], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION, [
            'workspace_id' => 'phase-3t-other-workspace',
        ]),
        phase3vRolloutPlan($workspace, [$objectiveB], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE),
    )->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objectiveA->id,
        'limit' => 1,
    ]);

    expect($decision['allowed'])->toBeFalse()
        ->and($decision['blocked_reasons'])->toContain('phase_3t_workspace_scope_mismatch:phase-3t-other-workspace')
        ->and($decision['blocked_reasons'])->toContain('phase_3u_objective_scope_mismatch');
});

it('blocks when metadata_only_ok review is not acknowledged', function (): void {
    [, $workspace, , $objective] = phase3vContext('phase-3v-metadata-ack');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    phase3vAllowScope($workspace, [$objective]);

    $decision = phase3vGuard(
        phase3vReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION),
        phase3vRolloutPlan($workspace, [$objective], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE),
    )->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($decision['allowed'])->toBeFalse()
        ->and($decision['blocked_reasons'])->toContain('metadata_only_ok_review_not_acknowledged')
        ->and($decision['required_operator_acknowledgements'])->toContain('metadata_only_ok_review');
});

it('blocks on duplicate risk order mismatch lifecycle blockers or continuity blockers', function (array $phase3tOverrides, array $phase3uOverrides, array $expectedReasons): void {
    [, $workspace, , $objective] = phase3vContext('phase-3v-risk-blockers');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    phase3vAllowScope($workspace, [$objective], true);

    $decision = phase3vGuard(
        phase3vReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION, $phase3tOverrides),
        phase3vRolloutPlan($workspace, [$objective], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE, $phase3uOverrides),
    )->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($decision['allowed'])->toBeFalse();

    foreach ($expectedReasons as $reason) {
        expect($decision['blocked_reasons'])->toContain($reason);
    }
})->with([
    'phase 3t duplicate risk' => [
        ['objective_rows' => [['duplicate_open_action_risk_count' => 1]]],
        [],
        ['duplicate_open_action_risk_is_not_zero'],
    ],
    'phase 3u duplicate confirmation missing' => [
        [],
        ['duplicate_risk_confirmation' => ['confirmed' => false]],
        ['phase_3u_duplicate_risk_not_confirmed_zero'],
    ],
    'phase 3t order mismatch' => [
        ['objective_rows' => [['canonical_legacy_order_exact_match' => false]]],
        [],
        ['phase_3t_order_parity_not_confirmed'],
    ],
    'phase 3u order parity missing' => [
        [],
        ['order_parity_confirmation' => ['confirmed' => false]],
        ['phase_3u_order_parity_not_confirmed'],
    ],
    'continuity blockers' => [
        ['objective_rows' => [['phase_3i_continuity_status' => 'blocked']]],
        [],
        ['phase_3i_continuity_blockers_present'],
    ],
    'lifecycle blockers' => [
        ['objective_rows' => [['phase_3j_lifecycle_status' => 'lifecycle_conflict']]],
        [],
        ['phase_3j_lifecycle_blockers_present'],
    ],
]);

it('allows only when all guarded scoped conditions pass', function (): void {
    [, $workspace, , $objective] = phase3vContext('phase-3v-allowed');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    phase3vAllowScope($workspace, [$objective]);

    $decision = phase3vGuard(
        phase3vReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION),
        phase3vRolloutPlan($workspace, [$objective], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE),
    )->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
        'ack_metadata_only_review' => true,
    ]);

    expect($decision['allowed'])->toBeTrue()
        ->and($decision['blocked_reasons'])->toBe([])
        ->and($decision['mode'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeGuardService::MODE)
        ->and($decision['runtime_activation_statement'])->toContain('Default selection remains legacy-first');
});

it('does not write actions migrate ownership sync lifecycle mutate dedupe or statuses or dispatch jobs', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase3vContext('phase-3v-read-only');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    phase3vAllowScope($workspace, [$objective], true);

    $legacy = phase3vOpportunity($objective);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => ['title' => 'Phase 3V read only'],
    ]);
    $before = [
        'legacy' => $legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']),
        'action' => $action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']),
        'actions' => AgenticMarketingAction::query()->count(),
        'opportunities' => AgenticMarketingOpportunity::query()->count(),
        'runs' => AgenticMarketingRun::query()->count(),
        'run_items' => AgenticMarketingRunItem::query()->count(),
        'audit_logs' => AgenticMarketingAuditLog::query()->count(),
        'canonical' => Opportunity::query()->count(),
    ];

    $decision = phase3vGuard(
        phase3vReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION),
        phase3vRolloutPlan($workspace, [$objective], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE),
    )->decide([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($decision['allowed'])->toBeTrue()
        ->and($legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']))->toBe($before['legacy'])
        ->and($action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']))->toBe($before['action'])
        ->and(AgenticMarketingAction::query()->count())->toBe($before['actions'])
        ->and(AgenticMarketingOpportunity::query()->count())->toBe($before['opportunities'])
        ->and(AgenticMarketingRun::query()->count())->toBe($before['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($before['run_items'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($before['audit_logs'])
        ->and(Opportunity::query()->count())->toBe($before['canonical']);

    Bus::assertNothingDispatched();
});

it('prints scoped runtime guard inspection status from the Phase 3V command', function (): void {
    [, $workspace, , $objective] = phase3vContext('phase-3v-command');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    phase3vAllowScope($workspace, [$objective]);
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeGuardService::class, phase3vGuard(
        phase3vReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION),
        phase3vRolloutPlan($workspace, [$objective], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE),
    ));

    $this->artisan('mos:inspect-agentic-planner-default-selection-scoped-runtime-guard', [
        '--workspace' => (string) $workspace->id,
        '--objectives' => (string) $objective->id,
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Guarded Phase 3V Agentic planner default-selection scoped runtime guard.')
        ->expectsOutputToContain('config flag status: enabled')
        ->expectsOutputToContain('phase 3t status: ready_for_scoped_expansion')
        ->expectsOutputToContain('phase 3u eligibility: eligible')
        ->expectsOutputToContain('final runtime guard decision: allowed')
        ->expectsOutputToContain('runtime use would still remain legacy-first: yes');
});

it('prints disabled flag blocked reasons rollback mode and legacy first runtime statement from the command', function (): void {
    [, $workspace, , $objective] = phase3vContext('phase-3v-command-disabled');
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', false);
    phase3vAllowScope($workspace, [$objective], true);
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeGuardService::class, phase3vGuard(
        phase3vReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION),
        phase3vRolloutPlan($workspace, [$objective], AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE),
    ));

    $this->artisan('mos:inspect-agentic-planner-default-selection-scoped-runtime-guard', [
        '--workspace' => (string) $workspace->id,
        '--objectives' => (string) $objective->id,
        '--limit' => 1,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('config flag status: disabled')
        ->expectsOutputToContain('allowed scope status:')
        ->expectsOutputToContain('phase 3t status: ready_for_scoped_expansion')
        ->expectsOutputToContain('phase 3u eligibility: eligible')
        ->expectsOutputToContain('final runtime guard decision: blocked')
        ->expectsOutputToContain('blocked reasons: scoped_runtime_feature_flag_disabled')
        ->expectsOutputToContain('rollback mode: legacy_first')
        ->expectsOutputToContain('runtime use would still remain legacy-first: yes');
});
