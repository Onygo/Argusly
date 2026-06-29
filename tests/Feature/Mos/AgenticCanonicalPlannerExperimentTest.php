<?php

use App\Enums\AgenticMarketingActionType;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerApplyExperimentService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerDryRunAction;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerDryRunAdapter;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerExperimentService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityActionSignatureService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerApplyExperimentAuditService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerReadinessInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3mContext(string $slug = 'phase-3m'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3M '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3M Workspace',
        'display_name' => 'Phase 3M Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3M Site',
        'site_url' => 'https://phase-3m.test',
        'base_url' => 'https://phase-3m.test',
        'allowed_domains' => ['phase-3m.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3M objective',
        'goal' => 'Compare guarded planner candidates',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3mOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    $token = 'phase-3m-'.Str::lower(Str::random(8));

    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3M planner experiment',
        'type' => 'content_network',
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Planner experiment contract '.$token,
            'reasoning' => 'Canonical candidates should be compared without changing planner selection.',
            'recommendation' => 'Keep legacy planner behaviour.',
            'primary_search_intent' => 'implementation',
            'target_audience' => 'content teams',
            'signals' => [
                'cluster_id' => $token,
                'cluster_name' => 'Planner experiment contract '.$token,
                'topic_keyword' => 'Planner experiment contract '.$token,
                'gap_type' => 'missing_pillar',
            ],
            'score_explanation' => [
                'summary' => 'Experiment comparison is diagnostic only.',
                'impact_score' => 80,
                'confidence_score' => 75,
                'effort_score' => 45,
            ],
        ],
    ], $overrides));
}

function phase3mCanonical(AgenticMarketingOpportunity $legacy, array $overrides = []): Opportunity
{
    $legacy->loadMissing('objective');

    return Opportunity::factory()->create(array_merge([
        'organization_id' => $legacy->objective->organization_id,
        'workspace_id' => $legacy->objective->workspace_id,
        'client_site_id' => $legacy->objective->client_site_id,
        'agentic_marketing_opportunity_id' => $legacy->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::DISMISSED,
        'title' => 'Canonical Phase 3M planner experiment',
        'topic' => 'Planner experiment contract',
        'summary' => 'Linked canonical context for planner comparison.',
        'priority_score' => 70,
        'recommended_actions' => [['title' => 'Do not create canonical actions']],
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

function phase3mAction(AgenticMarketingOpportunity $opportunity, array $overrides = []): AgenticMarketingAction
{
    $opportunity->loadMissing('objective');
    $title = (string) data_get($opportunity->payload, 'signals.topic_keyword', 'Planner experiment contract');

    return AgenticMarketingAction::query()->create(array_replace_recursive([
        'objective_id' => (string) $opportunity->objective_id,
        'opportunity_id' => (string) $opportunity->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'workspace_id' => (string) $opportunity->objective->workspace_id,
            'client_site_id' => (string) $opportunity->objective->client_site_id,
            'title' => $title,
            'planning' => [
                'planner' => AgenticMarketingActionPlanner::class,
                'source_opportunity_type' => (string) $opportunity->type,
            ],
        ],
    ], $overrides));
}

function phase3oAction(
    AgenticMarketingObjective $objective,
    array $legacyOverrides = [],
    array $canonicalOverrides = [],
    array $actionOverrides = [],
    array $metadataOverrides = [],
): array {
    $legacy = phase3mOpportunity($objective, array_replace_recursive(['status' => 'dismissed'], $legacyOverrides));
    $canonical = phase3mCanonical($legacy, $canonicalOverrides);
    $action = phase3mAction($legacy, $actionOverrides);
    $signature = app(AgenticOpportunityActionSignatureService::class)
        ->forCanonicalActionCandidate($canonical, (string) $action->action_type);
    $payload = (array) $action->payload;
    $payload['planner_experiment'] = array_replace_recursive([
        'version' => AgenticCanonicalPlannerApplyExperimentService::METADATA_VERSION,
        'canonical_opportunity_id' => (string) $canonical->id,
        'legacy_agentic_marketing_opportunity_id' => (string) $legacy->id,
        'objective_id' => (string) $objective->id,
        'workspace_id' => (string) $objective->workspace_id,
        'selection_source' => 'canonical_experiment',
        'phase_3m_signature' => $signature['signature'],
        'phase_3l_readiness_status' => AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY,
        'applied_at' => now()->toIso8601String(),
        'applied_by' => 'command',
    ], $metadataOverrides);

    DB::table($action->getTable())
        ->where('id', $action->id)
        ->update(['payload' => json_encode($payload, JSON_UNESCAPED_SLASHES)]);

    return [$legacy, $canonical, $action->refresh()];
}

it('keeps the canonical planner experiment feature flag off by default', function (): void {
    expect(config('features.mos_agentic_planner_canonical_experiment'))->toBeFalse();
});

it('keeps the canonical planner apply experiment feature flag off by default', function (): void {
    expect(config('features.mos_agentic_planner_canonical_apply_experiment'))->toBeFalse();
});

it('preserves legacy candidate order separately from canonical experiment order', function (): void {
    [, , , $objective] = phase3mContext('phase-3m-order');
    $low = phase3mOpportunity($objective, ['title' => 'Low legacy', 'priority_score' => 20]);
    $high = phase3mOpportunity($objective, ['title' => 'High legacy', 'priority_score' => 90]);

    $report = app(AgenticCanonicalPlannerExperimentService::class)->compare($objective);

    expect(collect($report['legacy_order'])->pluck('legacy_opportunity_id')->all())->toBe([(string) $high->id, (string) $low->id])
        ->and($report['summary']['legacy_candidate_count'])->toBe(2)
        ->and($report['canonical_experiment_order'])->toBe([]);
});

it('reports canonical experiment order for Phase 3L ready rows only', function (): void {
    [, , , $objective] = phase3mContext('phase-3m-canonical-order');
    $first = phase3mOpportunity($objective, ['title' => 'First ready', 'priority_score' => 20, 'status' => 'dismissed']);
    $second = phase3mOpportunity($objective, ['title' => 'Second ready', 'priority_score' => 90, 'status' => 'dismissed']);
    phase3mCanonical($first, ['priority_score' => 95]);
    phase3mCanonical($second, ['priority_score' => 40]);

    $report = app(AgenticCanonicalPlannerExperimentService::class)->compare($objective);

    expect(collect($report['canonical_experiment_order'])->pluck('legacy_opportunity_id')->all())->toBe([(string) $first->id, (string) $second->id])
        ->and($report['summary']['canonical_ready_candidate_count'])->toBe(2)
        ->and($report['summary']['recommendation'])->toBe('safe for scoped dry-run');
});

it('excludes blocked and not-ready rows from canonical experiment order', function (): void {
    [, , , $objective] = phase3mContext('phase-3m-blocked');
    $ready = phase3mOpportunity($objective, ['title' => 'Ready row', 'status' => 'dismissed']);
    $legacyOnly = phase3mOpportunity($objective, ['title' => 'Legacy only row', 'status' => 'dismissed']);
    $metadataOnly = phase3mOpportunity($objective, ['title' => 'Metadata only row']);
    phase3mCanonical($ready);
    phase3mCanonical($metadataOnly, ['status' => OpportunityStatus::OPEN]);

    $report = app(AgenticCanonicalPlannerExperimentService::class)->compare($objective);

    expect(collect($report['canonical_experiment_order'])->pluck('legacy_opportunity_id')->all())->toBe([(string) $ready->id])
        ->and(collect($report['excluded_rows'])->pluck('legacy_opportunity_id')->all())->toContain((string) $legacyOnly->id, (string) $metadataOnly->id)
        ->and($report['summary']['blocked_candidate_count'])->toBe(2);
});

it('blocks duplicate open legacy actions from experiment candidates', function (): void {
    [, , , $objective] = phase3mContext('phase-3m-duplicate-risk');
    $legacy = phase3mOpportunity($objective, ['status' => 'dismissed']);
    phase3mCanonical($legacy);
    phase3mAction($legacy);

    $report = app(AgenticCanonicalPlannerExperimentService::class)->compare($objective);

    expect($report['canonical_experiment_order'])->toBe([])
        ->and($report['summary']['duplicate_risk_count'])->toBe(1)
        ->and($report['summary']['recommendation'])->toBe('blocked')
        ->and(collect($report['excluded_rows'])->first()['blocked_reasons'])->toContain('canonical_action_would_duplicate_open_legacy_action');
});

it('keeps the comparison command read-only', function (): void {
    [, $workspace, , $objective] = phase3mContext('phase-3m-command');
    $legacy = phase3mOpportunity($objective, ['status' => 'dismissed']);
    $canonical = phase3mCanonical($legacy);
    $legacyUpdatedAt = $legacy->updated_at?->toIso8601String();
    $canonicalUpdatedAt = $canonical->updated_at?->toIso8601String();
    $counts = [
        'actions' => AgenticMarketingAction::query()->count(),
        'runs' => AgenticMarketingRun::query()->count(),
        'run_items' => AgenticMarketingRunItem::query()->count(),
        'audit_logs' => AgenticMarketingAuditLog::query()->count(),
        'canonical' => Opportunity::query()->count(),
    ];

    DB::enableQueryLog();

    $this->artisan('mos:compare-agentic-planner-candidates', [
        '--workspace' => (string) $workspace->id,
        '--limit' => 5,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Phase 3M Agentic planner candidate comparison.')
        ->expectsOutputToContain('inspected objectives: 1')
        ->expectsOutputToContain('legacy candidate count: 0')
        ->expectsOutputToContain('canonical-ready candidate count: 1')
        ->expectsOutputToContain('blocked candidate count: 0')
        ->expectsOutputToContain('sample legacy order:')
        ->expectsOutputToContain('sample canonical experiment order:')
        ->expectsOutputToContain('excluded row samples:')
        ->expectsOutputToContain('recommendation: safe for scoped dry-run');

    $writeQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $query) === 1)
        ->values();

    expect($writeQueries)->toHaveCount(0)
        ->and($legacy->refresh()->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and($canonical->refresh()->updated_at?->toIso8601String())->toBe($canonicalUpdatedAt)
        ->and(AgenticMarketingAction::query()->count())->toBe($counts['actions'])
        ->and(AgenticMarketingRun::query()->count())->toBe($counts['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($counts['run_items'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($counts['audit_logs'])
        ->and(Opportunity::query()->count())->toBe($counts['canonical']);
});

it('dry-run adapter returns DTOs only and writes no planner side effects', function (): void {
    [, , , $objective] = phase3mContext('phase-3m-dry-run');
    $legacy = phase3mOpportunity($objective, ['status' => 'dismissed']);
    phase3mCanonical($legacy);
    $readiness = app(AgenticPlannerReadinessInspectionService::class)->inspect($legacy);
    $counts = [
        'actions' => AgenticMarketingAction::query()->count(),
        'runs' => AgenticMarketingRun::query()->count(),
        'run_items' => AgenticMarketingRunItem::query()->count(),
        'audit_logs' => AgenticMarketingAuditLog::query()->count(),
    ];

    $actions = app(AgenticCanonicalPlannerDryRunAdapter::class)->proposeForReadyRow($legacy, $readiness);

    expect($actions)->not->toBeEmpty()
        ->and($actions[0])->toBeInstanceOf(AgenticCanonicalPlannerDryRunAction::class)
        ->and($actions[0]->toArray()['canonical_opportunity_id'])->toBe($readiness['linked_canonical_opportunity_id'])
        ->and(AgenticMarketingAction::query()->count())->toBe($counts['actions'])
        ->and(AgenticMarketingRun::query()->count())->toBe($counts['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($counts['run_items'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($counts['audit_logs']);
});

it('does not change default AgenticMarketingActionPlanner output', function (): void {
    [, , , $objective] = phase3mContext('phase-3m-default-planner');
    $legacy = phase3mOpportunity($objective);
    $canonical = phase3mCanonical($legacy, ['status' => OpportunityStatus::OPEN]);
    $canonicalActionsBefore = $canonical->recommended_actions;

    app(AgenticCanonicalPlannerExperimentService::class)->compare($objective);
    $summary = app(AgenticMarketingActionPlanner::class)->planForOpportunity($legacy);

    $actions = AgenticMarketingAction::query()->where('objective_id', $objective->id)->get();

    expect($summary['created'])->toBe(1)
        ->and($summary['reused'])->toBe(0)
        ->and($actions)->toHaveCount(1)
        ->and($actions->first()->opportunity_id)->toBe((string) $legacy->id)
        ->and($actions->first()->payload['planning']['planner'])->toBe(AgenticMarketingActionPlanner::class)
        ->and($actions->first()->payload['planning'])->not->toHaveKey('canonical_planner_experiment')
        ->and($canonical->refresh()->recommended_actions)->toBe($canonicalActionsBefore);
});

it('requires objective and limit filters for the Phase 3N apply experiment command', function (): void {
    $this->artisan('mos:apply-agentic-planner-canonical-experiment', [
        '--limit' => 5,
    ])
        ->assertFailed()
        ->expectsOutputToContain('Objective filter required');

    [, , , $objective] = phase3mContext('phase-3n-required-limit');

    $this->artisan('mos:apply-agentic-planner-canonical-experiment', [
        '--objective' => (string) $objective->id,
    ])
        ->assertFailed()
        ->expectsOutputToContain('Limit required');
});

it('blocks Phase 3N apply when the feature flag is disabled', function (): void {
    [, , , $objective] = phase3mContext('phase-3n-flag-blocked');
    $legacy = phase3mOpportunity($objective, ['status' => 'dismissed']);
    phase3mCanonical($legacy);

    $this->artisan('mos:apply-agentic-planner-canonical-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 5,
        '--apply' => true,
    ])
        ->assertFailed()
        ->expectsOutputToContain('Apply blocked: enable ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_APPLY_EXPERIMENT');

    expect(AgenticMarketingAction::query()->count())->toBe(0);
});

it('keeps Phase 3N dry-run read-only without --apply', function (): void {
    [, , , $objective] = phase3mContext('phase-3n-dry-run');
    $legacy = phase3mOpportunity($objective, ['status' => 'dismissed']);
    phase3mCanonical($legacy);

    $this->artisan('mos:apply-agentic-planner-canonical-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 5,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Dry run only for Phase 3N Agentic canonical planner experiment.')
        ->expectsOutputToContain('eligible apply candidate count: 1')
        ->expectsOutputToContain('created action count: 0')
        ->expectsOutputToContain('legacy opportunity ids: '.$legacy->id);

    expect(AgenticMarketingAction::query()->count())->toBe(0)
        ->and(Opportunity::query()->count())->toBe(1);
});

it('skips non-ready Phase 3L rows in the Phase 3N apply experiment', function (): void {
    config(['features.mos_agentic_planner_canonical_apply_experiment' => true]);
    [, , , $objective] = phase3mContext('phase-3n-non-ready');
    phase3mOpportunity($objective, ['status' => 'dismissed']);

    $this->artisan('mos:apply-agentic-planner-canonical-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 5,
        '--apply' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('eligible apply candidate count: 0')
        ->expectsOutputToContain('skipped candidate count: 1')
        ->expectsOutputToContain('created action count: 0');

    expect(AgenticMarketingAction::query()->count())->toBe(0);
});

it('blocks duplicate open legacy action risk before Phase 3N apply', function (): void {
    config(['features.mos_agentic_planner_canonical_apply_experiment' => true]);
    [, , , $objective] = phase3mContext('phase-3n-duplicate-blocked');
    $legacy = phase3mOpportunity($objective, ['status' => 'dismissed']);
    phase3mCanonical($legacy);
    phase3mAction($legacy);

    $this->artisan('mos:apply-agentic-planner-canonical-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 5,
        '--apply' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('eligible apply candidate count: 0')
        ->expectsOutputToContain('blocked count: 1')
        ->expectsOutputToContain('canonical_action_would_duplicate_open_legacy_action');

    expect(AgenticMarketingAction::query()->count())->toBe(1);
});

it('blocks Phase 3I continuity blockers before Phase 3N apply', function (): void {
    config(['features.mos_agentic_planner_canonical_apply_experiment' => true]);
    [, , , $objective] = phase3mContext('phase-3n-continuity-blocked');
    $legacy = phase3mOpportunity($objective, ['status' => 'dismissed']);
    phase3mCanonical($legacy);
    phase3mAction($legacy, ['status' => AgenticMarketingAction::STATUS_COMPLETED]);

    $this->artisan('mos:apply-agentic-planner-canonical-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 5,
        '--apply' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('eligible apply candidate count: 0')
        ->expectsOutputToContain('canonical_parent_only_lookup_would_miss_actions');
});

it('blocks Phase 3J lifecycle ambiguity before Phase 3N apply', function (): void {
    config(['features.mos_agentic_planner_canonical_apply_experiment' => true]);
    [, , , $objective] = phase3mContext('phase-3n-lifecycle-blocked');
    $legacy = phase3mOpportunity($objective, ['status' => 'open']);
    phase3mCanonical($legacy, ['status' => OpportunityStatus::OPEN]);

    $this->artisan('mos:apply-agentic-planner-canonical-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 5,
        '--apply' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('eligible apply candidate count: 0')
        ->expectsOutputToContain('phase_3j_lifecycle_status_ambiguous');

    expect(AgenticMarketingAction::query()->count())->toBe(0);
});

it('blocks Phase 3H signature blockers before Phase 3N apply', function (): void {
    config(['features.mos_agentic_planner_canonical_apply_experiment' => true]);
    [, , , $objective] = phase3mContext('phase-3n-signature-blocked');
    $legacy = phase3mOpportunity($objective, [
        'status' => 'dismissed',
        'type' => 'unknown_agentic_type',
    ]);
    phase3mCanonical($legacy);

    $this->artisan('mos:apply-agentic-planner-canonical-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 5,
        '--apply' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('eligible apply candidate count: 0')
        ->expectsOutputToContain('missing_candidate_action_type');

    expect(AgenticMarketingAction::query()->count())->toBe(0);
});

it('applies Phase 3N through legacy-owned AgenticMarketingAction rows with metadata only', function (): void {
    config(['features.mos_agentic_planner_canonical_apply_experiment' => true]);
    [, , , $objective] = phase3mContext('phase-3n-apply');
    $legacy = phase3mOpportunity($objective, ['status' => 'dismissed']);
    $canonical = phase3mCanonical($legacy);
    $canonicalRecommendedActions = $canonical->recommended_actions;

    $this->artisan('mos:apply-agentic-planner-canonical-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 5,
        '--apply' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Applying Phase 3N Agentic canonical planner experiment.')
        ->expectsOutputToContain('eligible apply candidate count: 1')
        ->expectsOutputToContain('created action count: 1')
        ->expectsOutputToContain('linked canonical opportunity ids: '.$canonical->id)
        ->expectsOutputToContain('rollback note: Execution remains legacy-owned through AgenticMarketingOpportunity ids.');

    $action = AgenticMarketingAction::query()->firstOrFail();

    expect($action->opportunity_id)->toBe((string) $legacy->id)
        ->and($action->opportunity)->toBeInstanceOf(AgenticMarketingOpportunity::class)
        ->and($action->opportunity_id)->not->toBe((string) $canonical->id)
        ->and($action->payload['planner_experiment']['version'])->toBe('agentic-planner-canonical-apply:v1')
        ->and($action->payload['planner_experiment']['canonical_opportunity_id'])->toBe((string) $canonical->id)
        ->and($action->payload['planner_experiment']['legacy_agentic_marketing_opportunity_id'])->toBe((string) $legacy->id)
        ->and($action->payload['planner_experiment']['selection_source'])->toBe('canonical_experiment')
        ->and($action->payload['planner_experiment']['phase_3l_readiness_status'])->toBe(AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY)
        ->and($action->payload['planner_experiment']['applied_by'])->toBe('command')
        ->and($canonical->refresh()->recommended_actions)->toBe($canonicalRecommendedActions)
        ->and(Opportunity::query()->count())->toBe(1);
});

it('keeps the Phase 3O audit command read-only', function (): void {
    [, , , $objective] = phase3mContext('phase-3o-read-only');
    [, , $action] = phase3oAction($objective);
    $original = [
        'payload' => $action->payload,
        'status' => $action->status,
        'opportunity_id' => $action->opportunity_id,
        'dedupe_hash' => $action->dedupe_hash,
    ];

    DB::enableQueryLog();

    $this->artisan('mos:audit-agentic-planner-apply-experiment', [
        '--objective' => (string) $objective->id,
        '--limit' => 10,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Phase 3O Agentic planner apply experiment audit.')
        ->expectsOutputToContain('inspected action count: 1')
        ->expectsOutputToContain('metadata only ok count: 1')
        ->expectsOutputToContain('recommended next step: Keep default planner legacy-owned');

    $writeQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $query) === 1)
        ->values();

    expect($writeQueries)->toHaveCount(0)
        ->and($action->refresh()->payload)->toBe($original['payload'])
        ->and($action->status)->toBe($original['status'])
        ->and($action->opportunity_id)->toBe($original['opportunity_id'])
        ->and($action->dedupe_hash)->toBe($original['dedupe_hash']);
});

it('reports a clean Phase 3N action as clean', function (): void {
    [, , , $objective] = phase3mContext('phase-3o-clean');
    [, , $action] = phase3oAction($objective);

    $report = app(AgenticPlannerApplyExperimentAuditService::class)->audit([
        'objective' => (string) $objective->id,
        'limit' => 10,
    ]);

    expect($report['summary']['metadata_only_ok_count'])->toBe(1)
        ->and($report['rows'][0]['action_id'])->toBe((string) $action->id)
        ->and($report['rows'][0]['audit_status'])->toBe(AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK)
        ->and($report['rows'][0]['action_remains_legacy_owned'])->toBeTrue()
        ->and($report['rows'][0]['phase_3h_source_signature_matches'])->toBeTrue();
});

it('reports missing legacy parent in the Phase 3O audit', function (): void {
    [, , , $objective] = phase3mContext('phase-3o-missing-legacy');
    phase3oAction($objective, metadataOverrides: [
        'legacy_agentic_marketing_opportunity_id' => (string) Str::uuid(),
    ]);

    $row = app(AgenticPlannerApplyExperimentAuditService::class)->audit([
        'objective' => (string) $objective->id,
        'limit' => 10,
    ])['rows'][0];

    expect($row['audit_status'])->toBe(AgenticPlannerApplyExperimentAuditService::STATUS_MISSING_LEGACY_PARENT)
        ->and($row['action_remains_legacy_owned'])->toBeFalse();
});

it('reports missing canonical opportunity in the Phase 3O audit', function (): void {
    [, , , $objective] = phase3mContext('phase-3o-missing-canonical');
    [, $canonical] = phase3oAction($objective);
    $canonical->forceDelete();

    $row = app(AgenticPlannerApplyExperimentAuditService::class)->audit([
        'objective' => (string) $objective->id,
        'limit' => 10,
    ])['rows'][0];

    expect($row['audit_status'])->toBe(AgenticPlannerApplyExperimentAuditService::STATUS_MISSING_CANONICAL_CONTEXT)
        ->and($row['canonical_opportunity_exists'])->toBeFalse();
});

it('reports canonical bridge mismatch in the Phase 3O audit', function (): void {
    [, , , $objective] = phase3mContext('phase-3o-bridge-mismatch');
    [, $canonical] = phase3oAction($objective);
    $otherLegacy = phase3mOpportunity($objective, ['status' => 'dismissed']);

    $canonical->forceFill(['agentic_marketing_opportunity_id' => (string) $otherLegacy->id])->save();

    $row = app(AgenticPlannerApplyExperimentAuditService::class)->audit([
        'objective' => (string) $objective->id,
        'limit' => 10,
    ])['rows'][0];

    expect($row['audit_status'])->toBe(AgenticPlannerApplyExperimentAuditService::STATUS_BRIDGE_MISMATCH)
        ->and($row['canonical_bridge_points_back_to_same_legacy_opportunity'])->toBeFalse();
});

it('reports signature mismatch in the Phase 3O audit', function (): void {
    [, , , $objective] = phase3mContext('phase-3o-signature-mismatch');
    [, , $action] = phase3oAction($objective, metadataOverrides: ['phase_3m_signature' => hash('sha256', 'stale')]);

    $row = app(AgenticPlannerApplyExperimentAuditService::class)->audit([
        'objective' => (string) $objective->id,
        'limit' => 10,
    ])['rows'][0];

    expect($row['action_id'])->toBe((string) $action->id)
        ->and($row['audit_status'])->toBe(AgenticPlannerApplyExperimentAuditService::STATUS_SIGNATURE_MISMATCH)
        ->and($row['phase_3h_source_signature_matches'])->toBeFalse();
});

it('reports Phase 3L readiness regression in the Phase 3O audit', function (): void {
    [, , , $objective] = phase3mContext('phase-3o-readiness-regressed');
    [$legacy, $canonical, $action] = phase3oAction($objective);

    $legacy->forceFill(['type' => 'unknown_agentic_type'])->save();
    $payload = (array) $action->refresh()->payload;
    $payload['planner_experiment']['phase_3m_signature'] = app(AgenticOpportunityActionSignatureService::class)
        ->forCanonicalActionCandidate($canonical->refresh(), (string) $action->action_type)['signature'];
    DB::table($action->getTable())->where('id', $action->id)->update(['payload' => json_encode($payload, JSON_UNESCAPED_SLASHES)]);

    $row = app(AgenticPlannerApplyExperimentAuditService::class)->audit([
        'objective' => (string) $objective->id,
        'limit' => 10,
    ])['rows'][0];

    expect($row['audit_status'])->toBe(AgenticPlannerApplyExperimentAuditService::STATUS_READINESS_REGRESSED)
        ->and($row['phase_3l_readiness_would_pass_today'])->toBeFalse();
});

it('reports Phase 3I continuity regression in the Phase 3O audit', function (): void {
    [, , , $objective] = phase3mContext('phase-3o-continuity-regressed');
    [$legacy] = phase3oAction($objective);
    phase3mAction($legacy, [
        'status' => AgenticMarketingAction::STATUS_COMPLETED,
        'payload' => ['title' => 'Completed historical action'],
    ]);

    $row = app(AgenticPlannerApplyExperimentAuditService::class)->audit([
        'objective' => (string) $objective->id,
        'limit' => 10,
    ])['rows'][0];

    expect($row['audit_status'])->toBe(AgenticPlannerApplyExperimentAuditService::STATUS_CONTINUITY_RISK)
        ->and($row['phase_3i_continuity_would_pass_today'])->toBeFalse();
});

it('reports Phase 3J lifecycle risk in the Phase 3O audit', function (): void {
    [, , , $objective] = phase3mContext('phase-3o-lifecycle-risk');
    phase3oAction($objective, canonicalOverrides: ['status' => OpportunityStatus::OPEN]);

    $row = app(AgenticPlannerApplyExperimentAuditService::class)->audit([
        'objective' => (string) $objective->id,
        'limit' => 10,
    ])['rows'][0];

    expect($row['audit_status'])->toBe(AgenticPlannerApplyExperimentAuditService::STATUS_LIFECYCLE_RISK)
        ->and($row['phase_3j_lifecycle_has_become_ambiguous_or_conflicting'])->toBeTrue();
});

it('reports duplicate open action risk in the Phase 3O audit', function (): void {
    [, , , $objective] = phase3mContext('phase-3o-duplicate-risk');
    [$legacy] = phase3oAction($objective);
    $duplicate = phase3mAction($legacy, ['status' => AgenticMarketingAction::STATUS_COMPLETED]);
    DB::table($duplicate->getTable())
        ->where('id', $duplicate->id)
        ->update(['status' => AgenticMarketingAction::STATUS_PROPOSED]);

    $row = app(AgenticPlannerApplyExperimentAuditService::class)->audit([
        'objective' => (string) $objective->id,
        'limit' => 10,
    ])['rows'][0];

    expect($row['audit_status'])->toBe(AgenticPlannerApplyExperimentAuditService::STATUS_DUPLICATE_RISK)
        ->and($row['duplicate_open_action_risk_now_exists'])->toBeTrue();
});

it('keeps the Phase 3O rollback plan command read-only', function (): void {
    [, , , $objective] = phase3mContext('phase-3o-rollback-read-only');
    [, , $action] = phase3oAction($objective);
    $original = [
        'payload' => $action->payload,
        'status' => $action->status,
        'opportunity_id' => $action->opportunity_id,
        'dedupe_hash' => $action->dedupe_hash,
    ];

    DB::enableQueryLog();

    $this->artisan('mos:plan-agentic-planner-apply-experiment-rollback', [
        '--objective' => (string) $objective->id,
        '--limit' => 10,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Phase 3O rollback plan')
        ->expectsOutputToContain('inspected action count: 1')
        ->expectsOutputToContain('metadata rollback candidate count: 1')
        ->expectsOutputToContain('metadata paths that would be removed: payload.planner_experiment')
        ->expectsOutputToContain('recommendation: Default rollback remains flag-off');

    $writeQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $query) === 1)
        ->values();

    expect($writeQueries)->toHaveCount(0)
        ->and($action->refresh()->payload)->toBe($original['payload'])
        ->and($action->status)->toBe($original['status'])
        ->and($action->opportunity_id)->toBe($original['opportunity_id'])
        ->and($action->dedupe_hash)->toBe($original['dedupe_hash']);
});
