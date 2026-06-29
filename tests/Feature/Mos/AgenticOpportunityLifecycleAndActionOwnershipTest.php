<?php

use App\Enums\AgenticMarketingActionType;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionPipeline;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityActionSignatureService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalActionOwnershipPlanner;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityLifecycleInspectionService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityLifecycleMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3jContext(string $slug = 'phase-3j'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3J '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3J Workspace',
        'display_name' => 'Phase 3J Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3J Site',
        'site_url' => 'https://phase-3j.test',
        'base_url' => 'https://phase-3j.test',
        'allowed_domains' => ['phase-3j.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3J objective',
        'goal' => 'Inspect Agentic lifecycle and action ownership',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3jOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3J ownership planning',
        'type' => 'content_network',
        'priority_score' => 87,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Canonical action ownership',
            'reasoning' => 'Lifecycle mapping needs diagnostics first.',
            'recommendation' => 'Create the ownership planning page.',
            'signals' => [
                'cluster_id' => 'phase-3j',
                'cluster_name' => 'Canonical action ownership',
                'topic_keyword' => 'Canonical action ownership',
                'gap_type' => 'missing_pillar',
            ],
            'score_explanation' => [
                'summary' => 'Phase 3J needs read-only planning.',
                'impact_score' => 82,
                'confidence_score' => 78,
                'effort_score' => 44,
            ],
        ],
    ], $overrides));
}

function phase3jCanonical(AgenticMarketingOpportunity $legacy, array $overrides = []): Opportunity
{
    $legacy->loadMissing('objective');

    return Opportunity::factory()->create(array_merge([
        'organization_id' => $legacy->objective->organization_id,
        'workspace_id' => $legacy->objective->workspace_id,
        'client_site_id' => $legacy->objective->client_site_id,
        'agentic_marketing_opportunity_id' => $legacy->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical Phase 3J ownership planning',
        'topic' => 'Canonical action ownership',
        'summary' => 'Linked canonical lifecycle context.',
        'recommended_actions' => [['title' => 'Keep canonical actions blocked']],
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

function phase3jAction(AgenticMarketingOpportunity $opportunity, array $overrides = []): AgenticMarketingAction
{
    $opportunity->loadMissing('objective');

    return AgenticMarketingAction::query()->create(array_replace_recursive([
        'objective_id' => (string) $opportunity->objective_id,
        'opportunity_id' => (string) $opportunity->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 30,
        'payload' => [
            'workspace_id' => (string) $opportunity->objective->workspace_id,
            'client_site_id' => (string) $opportunity->objective->client_site_id,
            'title' => 'Canonical action ownership',
            'planning' => [
                'planner' => AgenticMarketingActionPlanner::class,
                'source_opportunity_type' => (string) $opportunity->type,
            ],
        ],
    ], $overrides));
}

it('maps Agentic lifecycle statuses as candidate-only canonical statuses', function (): void {
    $map = app(AgenticOpportunityLifecycleMap::class);

    expect($map->map('open')['candidate_canonical_status'])->toBe(['open', 'reviewing'])
        ->and($map->map('dismissed')['candidate_canonical_status'])->toBe(['dismissed'])
        ->and($map->map('completed')['candidate_canonical_status'])->toBe(['actioned', 'resolved'])
        ->and($map->map('open')['sync_safe'])->toBeFalse()
        ->and($map->map('dismissed')['reverse_safe'])->toBeFalse()
        ->and($map->map('completed')['sync_safe'])->toBeFalse();
});

it('reports unknown Agentic lifecycle statuses as unmapped', function (): void {
    $result = app(AgenticOpportunityLifecycleMap::class)->map('paused');

    expect($result['candidate_canonical_status'])->toBe([])
        ->and($result['unmapped'])->toBeTrue()
        ->and($result['blocked_reason'])->toBe('unmapped_agentic_status');
});

it('reports lifecycle status conflicts against linked canonical candidates', function (): void {
    [, , , $objective] = phase3jContext('phase-3j-conflict');
    $legacy = phase3jOpportunity($objective, ['status' => 'dismissed']);
    phase3jCanonical($legacy, ['status' => OpportunityStatus::OPEN]);

    $report = app(AgenticOpportunityLifecycleInspectionService::class)->inspect($legacy);

    expect($report['canonical_opportunity_id'])->not->toBeNull()
        ->and($report['candidate_mapped_canonical_status'])->toBe(['dismissed'])
        ->and($report['status_conflict'])->toBeTrue()
        ->and($report['blocked_reasons'])->toContain('canonical_status_conflicts_with_candidate_mapping');
});

it('reports lifecycle blockers when execution rows make status scope ambiguous', function (): void {
    [, , , $objective] = phase3jContext('phase-3j-ambiguous');
    $legacy = phase3jOpportunity($objective, ['status' => 'completed']);
    phase3jCanonical($legacy, ['status' => OpportunityStatus::ACTIONED]);
    phase3jAction($legacy, ['status' => AgenticMarketingAction::STATUS_COMPLETED]);
    AgenticMarketingExecutionPipeline::query()->create([
        'organization_id' => $objective->organization_id,
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'mode' => 'manual',
        'status' => 'completed',
        'current_stage' => 'done',
        'approval_status' => 'approved',
        'publishing_readiness' => 'ready',
    ]);

    $report = app(AgenticOpportunityLifecycleInspectionService::class)->inspect($legacy);

    expect($report['completed_has_completed_actions'])->toBeTrue()
        ->and($report['completed_has_completed_pipelines'])->toBeTrue()
        ->and($report['lifecycle_status_ambiguous'])->toBeTrue()
        ->and($report['blocked_reasons'])->toContain('completed_agentic_status_has_execution_completion_scope');
});

it('blocks canonical action ownership without a safe canonical bridge', function (): void {
    [, , , $objective] = phase3jContext('phase-3j-no-bridge');
    $legacy = phase3jOpportunity($objective);

    $plan = app(AgenticOpportunityCanonicalActionOwnershipPlanner::class)->plan($legacy);

    expect($plan['linked_canonical_opportunity_id'])->toBeNull()
        ->and($plan['canonical_action_ownership_blocked'])->toBeTrue()
        ->and($plan['blocked_reasons'])->toContain('missing_safe_canonical_bridge');
});

it('blocks canonical action ownership when duplicate canonical bridges exist', function (): void {
    [, , , $objective] = phase3jContext('phase-3j-duplicate-bridges');
    $legacy = phase3jOpportunity($objective);
    phase3jCanonical($legacy, ['dedupe_hash' => 'phase-3j-bridge-one-'.Str::random(8)]);
    phase3jCanonical($legacy, ['dedupe_hash' => 'phase-3j-bridge-two-'.Str::random(8)]);

    $plan = app(AgenticOpportunityCanonicalActionOwnershipPlanner::class)->plan($legacy);

    expect($plan['linked_canonical_opportunity_id'])->toBeNull()
        ->and($plan['canonical_action_ownership_blocked'])->toBeTrue()
        ->and($plan['blocked_reasons'])->toContain('multiple_canonical_opportunities_linked_to_agentic_row');
});

it('uses Phase 3H action signatures in canonical ownership planning', function (): void {
    [, , , $objective] = phase3jContext('phase-3j-signature');
    $legacy = phase3jOpportunity($objective);
    phase3jCanonical($legacy);

    $plan = app(AgenticOpportunityCanonicalActionOwnershipPlanner::class)->plan($legacy);

    expect($plan['canonical_equivalent_action_signature']['signature_version'])
        ->toBe(AgenticOpportunityActionSignatureService::SIGNATURE_VERSION)
        ->and($plan['proposed_metadata_for_future_action_payloads']['action_signature_version'])
        ->toBe(AgenticOpportunityActionSignatureService::SIGNATURE_VERSION);
});

it('respects Phase 3I continuity blockers and duplicate open action risk', function (): void {
    [, , , $objective] = phase3jContext('phase-3j-continuity');
    $legacy = phase3jOpportunity($objective);
    phase3jCanonical($legacy);
    $action = phase3jAction($legacy, ['status' => AgenticMarketingAction::STATUS_APPROVED]);
    AgenticActionRun::query()->create([
        'workspace_id' => (string) $objective->workspace_id,
        'goal_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_id' => (string) $action->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticActionRun::STATUS_RUNNING,
        'input_snapshot' => ['opportunity_id' => (string) $legacy->id],
    ]);

    $plan = app(AgenticOpportunityCanonicalActionOwnershipPlanner::class)->plan($legacy);

    expect($plan['canonical_action_ownership_blocked'])->toBeTrue()
        ->and($plan['blocked_reasons'])->toContain('phase_3i_canonical_parent_only_lookup_gaps')
        ->and($plan['blocked_reasons'])->toContain('canonical_parent_only_lookup_would_miss_actions')
        ->and($plan['blocked_reasons'])->toContain('canonical_action_would_duplicate_open_legacy_action');
});

it('keeps Phase 3J commands read-only', function (): void {
    [, $workspace, , $objective] = phase3jContext('phase-3j-commands');
    $legacy = phase3jOpportunity($objective);
    $canonical = phase3jCanonical($legacy);
    $action = phase3jAction($legacy);
    $legacyUpdatedAt = $legacy->updated_at?->toIso8601String();
    $canonicalUpdatedAt = $canonical->updated_at?->toIso8601String();
    $actionUpdatedAt = $action->updated_at?->toIso8601String();
    $opportunityCount = Opportunity::query()->count();
    $actionCount = AgenticMarketingAction::query()->count();

    DB::enableQueryLog();

    $this->artisan('mos:inspect-agentic-lifecycle-map', [
        '--workspace' => (string) $workspace->id,
        '--limit' => 5,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Agentic lifecycle mapping diagnostics.')
        ->expectsOutputToContain('inspected count: 1')
        ->expectsOutputToContain('linked canonical count: 1')
        ->expectsOutputToContain('blocked reason samples:');

    $this->artisan('mos:plan-agentic-canonical-action-ownership', [
        '--workspace' => (string) $workspace->id,
        '--limit' => 5,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Agentic canonical action ownership planning diagnostics.')
        ->expectsOutputToContain('inspected count: 1')
        ->expectsOutputToContain('linked canonical count: 1')
        ->expectsOutputToContain('signature samples:');

    $writeQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $query) === 1)
        ->values();

    expect($writeQueries)->toHaveCount(0)
        ->and($legacy->refresh()->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and($canonical->refresh()->updated_at?->toIso8601String())->toBe($canonicalUpdatedAt)
        ->and($action->refresh()->updated_at?->toIso8601String())->toBe($actionUpdatedAt)
        ->and(Opportunity::query()->count())->toBe($opportunityCount)
        ->and(AgenticMarketingAction::query()->count())->toBe($actionCount);
});
