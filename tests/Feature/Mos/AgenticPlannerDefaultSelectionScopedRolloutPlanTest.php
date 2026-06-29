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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionRolloutReadinessService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRolloutPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3uContext(string $slug = 'phase-3u'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3U '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3U Workspace',
        'display_name' => 'Phase 3U Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3U Site',
        'site_url' => 'https://phase-3u.test',
        'base_url' => 'https://phase-3u.test',
        'allowed_domains' => ['phase-3u.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = phase3uObjective($workspace, $site, $organization);

    return [$organization, $workspace, $site, $objective];
}

function phase3uObjective(Workspace $workspace, ClientSite $site, Organization $organization): AgenticMarketingObjective
{
    return AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3U objective '.Str::random(4),
        'goal' => 'Plan scoped default-selection rollout',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);
}

function phase3uOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3U scoped rollout plan',
        'type' => 'content_network',
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Phase 3U '.Str::lower(Str::random(8)),
            'reasoning' => 'Scoped rollout planning is diagnostic only.',
            'recommendation' => 'Keep legacy ownership.',
            'signals' => ['topic_keyword' => 'Phase 3U'],
        ],
    ], $overrides));
}

function phase3uReadinessReport(Workspace $workspace, array $objectives, string $status, array $overrides = []): array
{
    $rows = collect($objectives)
        ->map(fn (AgenticMarketingObjective $objective): array => [
            'objective_id' => (string) $objective->id,
            'workspace_id' => (string) $workspace->id,
            'site_id' => (string) $objective->client_site_id,
            'rollout_readiness_status' => $status,
            'blocked_reasons' => $status === AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION ? [] : ['phase_3t_blocked'],
            'candidate_action_count' => $status === AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_NO_CANDIDATE_SCOPE ? 0 : 1,
            'metadata_only_ok_count' => 0,
            'duplicate_open_action_risk_count' => 0,
            'canonical_coverage_sufficient' => $status !== AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_NO_CANDIDATE_SCOPE,
            'canonical_legacy_order_exact_match' => $status !== AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_NO_CANDIDATE_SCOPE,
            'metadata_only_action_ownership_approved' => false,
        ])
        ->values()
        ->all();

    $report = [
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
            : ($status === AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_NO_CANDIDATE_SCOPE ? 'keep legacy' : 'blocked'),
    ];

    return array_replace_recursive($report, $overrides);
}

function phase3uReadinessService(array $report): AgenticPlannerDefaultSelectionRolloutReadinessService
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

it('produces an eligible Phase 3U plan only when Phase 3T is ready_for_scoped_expansion', function (): void {
    [$organization, $workspace, $site, $objectiveA] = phase3uContext('phase-3u-ready');
    $objectiveB = phase3uObjective($workspace, $site, $organization);
    $readiness = phase3uReadinessService(phase3uReadinessReport($workspace, [$objectiveA, $objectiveB], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION));

    $plan = (new AgenticPlannerDefaultSelectionScopedRolloutPlanService($readiness))->plan([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objectiveA->id, (string) $objectiveB->id],
        'limit' => 1,
    ]);

    expect($plan['rollout_eligibility'])->toBe(AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE)
        ->and($plan['recommended_rollout_mode'])->toBe(AgenticPlannerDefaultSelectionScopedRolloutPlanService::ROLLOUT_MODE)
        ->and($plan['recommended_first_rollout_scope']['scope_type'])->toBe('explicit_workspace_objectives')
        ->and($plan['objectives_included'])->toHaveCount(2)
        ->and($plan['runtime_activation_enabled'])->toBeFalse()
        ->and($plan['legacy_action_ownership_preserved'])->toBeTrue()
        ->and($readiness->receivedInput['objectives'])->toBe([(string) $objectiveA->id, (string) $objectiveB->id]);
});

it('produces a blocked Phase 3U plan when Phase 3T is blocked_by_preview', function (): void {
    [, $workspace, , $objective] = phase3uContext('phase-3u-blocked-preview');
    $readiness = phase3uReadinessService(phase3uReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_PREVIEW));

    $plan = (new AgenticPlannerDefaultSelectionScopedRolloutPlanService($readiness))->plan([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($plan['rollout_eligibility'])->toBe(AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_BLOCKED)
        ->and($plan['blocked_reasons'])->toContain('phase_3t_status_not_ready_for_scoped_expansion:'.AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_BLOCKED_BY_PREVIEW)
        ->and($plan['objectives_included'])->toBe([])
        ->and($plan['objectives_excluded'][0]['objective_id'])->toBe((string) $objective->id);
});

it('produces a blocked Phase 3U plan when Phase 3T is no_candidate_scope', function (): void {
    [, $workspace, , $objective] = phase3uContext('phase-3u-no-candidate');
    $readiness = phase3uReadinessService(phase3uReadinessReport($workspace, [], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_NO_CANDIDATE_SCOPE));

    $plan = (new AgenticPlannerDefaultSelectionScopedRolloutPlanService($readiness))->plan([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($plan['rollout_eligibility'])->toBe(AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_BLOCKED)
        ->and($plan['readiness_status'])->toBe(AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_NO_CANDIDATE_SCOPE)
        ->and($plan['blocked_reasons'])->toContain('phase_3t_status_not_ready_for_scoped_expansion:'.AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_NO_CANDIDATE_SCOPE)
        ->and($plan['blocked_reasons'])->toContain('explicit_scope_did_not_resolve_all_requested_objectives');
});

it('fails closed when Phase 3T returns a different workspace scope', function (): void {
    [, $workspace, , $objective] = phase3uContext('phase-3u-workspace-mismatch');
    $report = phase3uReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION, [
        'workspace_id' => 'workspace-from-stale-report',
    ]);

    $plan = (new AgenticPlannerDefaultSelectionScopedRolloutPlanService(phase3uReadinessService($report)))->plan([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($plan['rollout_eligibility'])->toBe(AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_BLOCKED)
        ->and($plan['blocked_reasons'])->toContain('phase_3t_workspace_scope_mismatch:workspace-from-stale-report')
        ->and($plan['objectives_included'])->toBe([]);
});

it('fails closed when Phase 3T returns a different objective scope', function (): void {
    [$organization, $workspace, $site, $objectiveA] = phase3uContext('phase-3u-objective-mismatch');
    $objectiveB = phase3uObjective($workspace, $site, $organization);
    $readiness = phase3uReadinessService(phase3uReadinessReport($workspace, [$objectiveB], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION));

    $plan = (new AgenticPlannerDefaultSelectionScopedRolloutPlanService($readiness))->plan([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objectiveA->id,
        'limit' => 1,
    ]);

    expect($plan['rollout_eligibility'])->toBe(AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_BLOCKED)
        ->and($plan['blocked_reasons'])->toContain('phase_3t_objective_scope_mismatch')
        ->and($plan['objectives_included'])->toBe([])
        ->and($plan['objectives_excluded'][0]['objective_id'])->toBe((string) $objectiveA->id);
});

it('requires metadata_only_ok operator review and never approves ownership migration', function (): void {
    [, $workspace, , $objective] = phase3uContext('phase-3u-metadata-only');
    $report = phase3uReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION, [
        'summary' => ['metadata_only_ok_count' => 2],
        'objective_rows' => [
            [
                'objective_id' => (string) $objective->id,
                'rollout_readiness_status' => AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION,
                'candidate_action_count' => 1,
                'metadata_only_ok_count' => 2,
                'duplicate_open_action_risk_count' => 0,
                'canonical_coverage_sufficient' => true,
                'canonical_legacy_order_exact_match' => true,
                'metadata_only_action_ownership_approved' => true,
            ],
        ],
    ]);

    $plan = (new AgenticPlannerDefaultSelectionScopedRolloutPlanService(phase3uReadinessService($report)))->plan([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
        'include_metadata_only_ok' => true,
    ]);

    expect($plan['metadata_only_ok_review_requirement']['manual_review_required'])->toBeTrue()
        ->and($plan['metadata_only_ok_review_requirement']['ownership_migration_approved'])->toBeFalse()
        ->and($plan['objectives_included'][0]['metadata_only_action_ownership_approved'])->toBeFalse()
        ->and($plan['canonical_ids_metadata_selection_context_only'])->toBeTrue();
});

it('prints rollout and rollback checklists from the Phase 3U command', function (): void {
    [, $workspace, , $objective] = phase3uContext('phase-3u-command');
    app()->instance(AgenticPlannerDefaultSelectionScopedRolloutPlanService::class, new AgenticPlannerDefaultSelectionScopedRolloutPlanService(
        phase3uReadinessService(phase3uReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION))
    ));

    $this->artisan('mos:inspect-agentic-planner-default-selection-scoped-rollout-plan', [
        '--workspace' => (string) $workspace->id,
        '--objectives' => (string) $objective->id,
        '--limit' => 1,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Phase 3U Agentic planner default-selection scoped rollout plan.')
        ->expectsOutputToContain('Operator checklist')
        ->expectsOutputToContain('review metadata_only_ok rows manually')
        ->expectsOutputToContain('Rollback checklist')
        ->expectsOutputToContain('do not mutate action statuses')
        ->expectsOutputToContain('Runtime activation is still not enabled');
});

it('does not write actions metadata statuses dedupe hashes lifecycle state or dispatch jobs', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase3uContext('phase-3u-read-only');
    $legacy = phase3uOpportunity($objective);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => ['title' => 'Phase 3U read only'],
    ]);
    $planner = app(AgenticMarketingActionPlanner::class);
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

    $plan = (new AgenticPlannerDefaultSelectionScopedRolloutPlanService(
        phase3uReadinessService(phase3uReadinessReport($workspace, [$objective], AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION))
    ))->plan([
        'workspace' => (string) $workspace->id,
        'objectives' => (string) $objective->id,
        'limit' => 1,
    ]);

    expect($plan['rollout_eligibility'])->toBe(AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE)
        ->and($legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']))->toBe($before['legacy'])
        ->and($action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']))->toBe($before['action'])
        ->and(AgenticMarketingAction::query()->count())->toBe($before['actions'])
        ->and(AgenticMarketingOpportunity::query()->count())->toBe($before['opportunities'])
        ->and(AgenticMarketingRun::query()->count())->toBe($before['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($before['run_items'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($before['audit_logs'])
        ->and(Opportunity::query()->count())->toBe($before['canonical'])
        ->and(app(AgenticMarketingActionPlanner::class)::class)->toBe($planner::class);

    Bus::assertNothingDispatched();
});
