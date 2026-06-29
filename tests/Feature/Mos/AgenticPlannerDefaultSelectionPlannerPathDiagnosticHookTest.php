<?php

use App\Enums\AgenticMarketingActionType;
use App\Enums\AgenticMarketingOpportunityType;
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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3wContext(string $slug = 'phase-3w'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3W '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3W Workspace',
        'display_name' => 'Phase 3W Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3W Site',
        'site_url' => 'https://phase-3w.test',
        'base_url' => 'https://phase-3w.test',
        'allowed_domains' => ['phase-3w.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3W objective '.Str::random(4),
        'goal' => 'Keep scoped runtime diagnostics legacy-first',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3wOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3W planner path diagnostic',
        'type' => AgenticMarketingOpportunityType::ContentNetwork->value,
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Phase 3W '.Str::lower(Str::random(8)),
            'reasoning' => 'Planner-path scoped runtime guard diagnostics are read-only.',
            'recommendation' => 'Keep legacy-first default selection.',
            'signals' => ['topic_keyword' => 'Phase 3W'],
        ],
    ], $overrides));
}

function phase3wGuard(array $decision): AgenticPlannerDefaultSelectionScopedRuntimeGuardService
{
    return new class($decision) extends AgenticPlannerDefaultSelectionScopedRuntimeGuardService
    {
        public int $calls = 0;

        public array $receivedInput = [];

        public function __construct(private readonly array $decision) {}

        public function decide(array $input): array
        {
            $this->calls++;
            $this->receivedInput = $input;

            return $this->decision;
        }
    };
}

function phase3wDecision(bool $allowed, array $blockedReasons = []): array
{
    return [
        'allowed' => $allowed,
        'mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::MODE,
        'blocked_reasons' => $blockedReasons,
        'rollback_mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
        'runtime_activation_statement' => 'Scoped runtime guard inspection only. Default selection remains legacy-first; no planner default migration, action creation, ownership migration, lifecycle sync, dedupe/status mutation, payload mutation, route change, metadata write, execution parent rewrite, or job dispatch is performed.',
    ];
}

it('does not call the Phase 3V guard when the planner path flag is false', function (): void {
    [, , , $objective] = phase3wContext('phase-3w-flag-false');
    phase3wOpportunity($objective);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', false);
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeGuardService::class, new class extends AgenticPlannerDefaultSelectionScopedRuntimeGuardService
    {
        public function __construct() {}

        public function decide(array $input): array
        {
            throw new RuntimeException('Phase 3W should not call the guard when the flag is false.');
        }
    });

    $summary = app(AgenticMarketingActionPlanner::class)->planForObjective($objective);
    $action = AgenticMarketingAction::query()->firstOrFail();

    expect($summary['created'])->toBe(1)
        ->and($summary)->not->toHaveKey('default_selection_scoped_runtime_guard')
        ->and($action->opportunity_id)->toBe(AgenticMarketingOpportunity::query()->firstOrFail()->id)
        ->and(data_get($action->payload, 'planning.planner'))->toBe(AgenticMarketingActionPlanner::class)
        ->and(app()->bound(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::DIAGNOSTICS_KEY))->toBeFalse();
});

it('keeps the planner legacy when the Phase 3V guard blocks', function (): void {
    Bus::fake();
    [, $workspace, $site, $objective] = phase3wContext('phase-3w-blocked');
    $legacy = phase3wOpportunity($objective);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    $guard = phase3wGuard(phase3wDecision(false, ['phase_3t_status_not_ready_for_scoped_expansion:blocked']));
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeGuardService::class, $guard);

    $summary = app(AgenticMarketingActionPlanner::class)->planForObjective($objective);
    $diagnostics = app(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::DIAGNOSTICS_KEY);
    $action = AgenticMarketingAction::query()->firstOrFail();
    $run = AgenticMarketingRun::query()->firstOrFail();
    $item = AgenticMarketingRunItem::query()->firstOrFail();

    expect($guard->calls)->toBe(1)
        ->and($guard->receivedInput)->toBe([
            'workspace' => (string) $workspace->id,
            'objectives' => [(string) $objective->id],
            'site' => (string) $site->id,
            'limit' => 1,
        ])
        ->and($diagnostics['guard_called'])->toBeTrue()
        ->and($diagnostics['guard_allowed'])->toBeFalse()
        ->and($diagnostics['blocked_reasons'])->toContain('phase_3t_status_not_ready_for_scoped_expansion:blocked')
        ->and($diagnostics['selected_planner_remains'])->toBe('legacy')
        ->and($summary)->not->toHaveKey('default_selection_scoped_runtime_guard')
        ->and($action->opportunity_id)->toBe($legacy->id)
        ->and(data_get($action->payload, 'planning.planner'))->toBe(AgenticMarketingActionPlanner::class)
        ->and(data_get($run->result, 'default_selection_scoped_runtime_guard'))->toBeNull()
        ->and(data_get($item->result, 'default_selection_scoped_runtime_guard'))->toBeNull()
        ->and(data_get($action->payload, 'default_selection_scoped_runtime_guard'))->toBeNull();

    Bus::assertNothingDispatched();
});

it('keeps the planner legacy even when the Phase 3V guard allows', function (): void {
    Bus::fake();
    [, , , $objective] = phase3wContext('phase-3w-allowed');
    $legacy = phase3wOpportunity($objective);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    $guard = phase3wGuard(phase3wDecision(true));
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeGuardService::class, $guard);

    $summary = app(AgenticMarketingActionPlanner::class)->planForObjective($objective);
    $diagnostics = app(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::DIAGNOSTICS_KEY);
    $action = AgenticMarketingAction::query()->firstOrFail();
    $run = AgenticMarketingRun::query()->firstOrFail();
    $item = AgenticMarketingRunItem::query()->firstOrFail();

    expect($guard->calls)->toBe(1)
        ->and($diagnostics['guard_allowed'])->toBeTrue()
        ->and($diagnostics['blocked_reasons'])->toBe([])
        ->and($diagnostics['rollback_mode'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE)
        ->and($diagnostics['selected_planner_remains'])->toBe('legacy')
        ->and($summary['created'])->toBe(1)
        ->and($action->opportunity_id)->toBe($legacy->id)
        ->and($action->action_type)->toBe(AgenticMarketingActionType::CreateArticle->value)
        ->and(data_get($action->payload, 'planning.planner'))->toBe(AgenticMarketingActionPlanner::class)
        ->and(data_get($action->payload, 'default_selection_experiment'))->toBeNull()
        ->and(data_get($action->payload, 'default_selection_scoped_runtime_guard'))->toBeNull()
        ->and(data_get($run->payload, 'default_selection_scoped_runtime_guard'))->toBeNull()
        ->and(data_get($run->result, 'default_selection_scoped_runtime_guard'))->toBeNull()
        ->and(data_get($item->payload, 'default_selection_scoped_runtime_guard'))->toBeNull()
        ->and(data_get($item->result, 'default_selection_scoped_runtime_guard'))->toBeNull()
        ->and(Opportunity::query()->count())->toBe(0);

    Bus::assertNothingDispatched();
});

it('records diagnostics in process only and does not create actions mutate data sync lifecycle or dispatch jobs', function (): void {
    Bus::fake();
    [, , , $objective] = phase3wContext('phase-3w-in-process-only');
    $legacy = phase3wOpportunity($objective, ['status' => 'closed']);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => ['title' => 'Existing Phase 3W action'],
    ]);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_enabled', true);
    $guard = phase3wGuard(phase3wDecision(true));
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeGuardService::class, $guard);
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

    $diagnostics = app(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::class)->inspectObjective($objective);

    expect($guard->calls)->toBe(1)
        ->and($diagnostics)->toBe(app(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::DIAGNOSTICS_KEY))
        ->and($diagnostics['guard_called'])->toBeTrue()
        ->and($diagnostics['guard_allowed'])->toBeTrue()
        ->and($diagnostics['requested_scope'])->toBe([
            'workspace_id' => (string) $objective->workspace_id,
            'objective_ids' => [(string) $objective->id],
            'site_id' => (string) $objective->client_site_id,
        ])
        ->and($diagnostics['selected_planner_remains'])->toBe('legacy')
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
