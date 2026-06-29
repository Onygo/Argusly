<?php

use App\Enums\AgenticMarketingActionType;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityActionDedupeInspectionService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityActionSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3hContext(string $slug = 'phase-3h'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3H '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3H Workspace',
        'display_name' => 'Phase 3H Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3H Site',
        'site_url' => 'https://phase-3h.test',
        'base_url' => 'https://phase-3h.test',
        'allowed_domains' => ['phase-3h.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3H objective',
        'goal' => 'Inspect Agentic action dedupe',
        'locale' => 'en',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3hOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3H canonical action planning',
        'type' => 'content_network',
        'priority_score' => 82,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Agentic action planning',
            'recommendation' => 'Create the canonical planning guide.',
            'signals' => [
                'cluster_id' => 'phase-3h-cluster',
                'cluster_name' => 'Agentic action planning',
                'topic_keyword' => 'Agentic action planning',
                'gap_type' => 'missing_pillar',
            ],
            'score_explanation' => [
                'summary' => 'The topic needs a canonical action planning page.',
                'impact_score' => 80,
                'confidence_score' => 76,
                'effort_score' => 45,
            ],
        ],
    ], $overrides));
}

function phase3hCanonicalOpportunity(AgenticMarketingOpportunity $legacy, array $overrides = []): Opportunity
{
    $legacy->loadMissing('objective');

    return Opportunity::factory()->create(array_merge([
        'organization_id' => $legacy->objective->organization_id,
        'workspace_id' => $legacy->objective->workspace_id,
        'client_site_id' => $legacy->objective->client_site_id,
        'agentic_marketing_opportunity_id' => $legacy->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical Phase 3H action planning',
        'topic' => 'Agentic action planning',
        'summary' => 'Linked canonical action planning context.',
        'recommended_actions' => [['title' => 'Plan the canonical equivalent action']],
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

function phase3hAction(AgenticMarketingOpportunity $opportunity, array $overrides = []): AgenticMarketingAction
{
    return AgenticMarketingAction::query()->create(array_replace_recursive([
        'objective_id' => (string) $opportunity->objective_id,
        'opportunity_id' => (string) $opportunity->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'workspace_id' => (string) $opportunity->objective->workspace_id,
            'client_site_id' => (string) $opportunity->objective->client_site_id,
            'title' => 'Agentic action planning',
            'planning' => [
                'planner' => AgenticMarketingActionPlanner::class,
                'source_opportunity_type' => (string) $opportunity->type,
            ],
        ],
    ], $overrides));
}

it('keeps legacy action signatures stable across score and timestamp refreshes', function (): void {
    [, , , $objective] = phase3hContext('phase-3h-stable');
    $legacy = phase3hOpportunity($objective);
    $service = app(AgenticOpportunityActionSignatureService::class);

    $before = $service->forLegacyOpportunity($legacy, AgenticMarketingActionType::CreateArticle->value);

    $legacy->forceFill([
        'priority_score' => 41,
        'payload' => array_replace_recursive($legacy->payload, [
            'score_explanation' => [
                'summary' => 'Refreshed score only.',
                'impact_score' => 12,
                'confidence_score' => 11,
                'effort_score' => 10,
            ],
        ]),
    ])->save();

    $after = $service->forLegacyOpportunity($legacy->refresh(), AgenticMarketingActionType::CreateArticle->value);

    expect($before['signature'])->not->toBeNull()
        ->and($after['signature'])->toBe($before['signature']);
});

it('uses the same canonical-equivalent signature for linked legacy and canonical opportunities', function (): void {
    [, , , $objective] = phase3hContext('phase-3h-linked');
    $legacy = phase3hOpportunity($objective);
    $canonical = phase3hCanonicalOpportunity($legacy);
    $service = app(AgenticOpportunityActionSignatureService::class);

    $legacySignature = $service->forLegacyOpportunity($legacy, AgenticMarketingActionType::CreateArticle->value);
    $canonicalSignature = $service->forCanonicalOpportunity($canonical, AgenticMarketingActionType::CreateArticle->value);

    expect($legacySignature['blocked_reasons'])->toBe([])
        ->and($canonicalSignature['blocked_reasons'])->toBe([])
        ->and($canonicalSignature['signature'])->toBe($legacySignature['signature']);
});

it('keeps signatures distinct by objective and action type', function (): void {
    [, , , $firstObjective] = phase3hContext('phase-3h-different-a');
    [, , , $secondObjective] = phase3hContext('phase-3h-different-b');
    $first = phase3hOpportunity($firstObjective);
    $second = phase3hOpportunity($secondObjective);
    $service = app(AgenticOpportunityActionSignatureService::class);

    $firstCreate = $service->forLegacyOpportunity($first, AgenticMarketingActionType::CreateArticle->value);
    $secondCreate = $service->forLegacyOpportunity($second, AgenticMarketingActionType::CreateArticle->value);
    $firstMeta = $service->forLegacyOpportunity($first, AgenticMarketingActionType::UpdateMeta->value);

    expect($firstCreate['signature'])->not->toBe($secondCreate['signature'])
        ->and($firstCreate['signature'])->not->toBe($firstMeta['signature']);
});

it('reports missing signature context as blocked without inferring values', function (): void {
    $organization = Organization::query()->create([
        'name' => 'Phase 3H Missing '.Str::random(6),
        'slug' => 'phase-3h-missing-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);
    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => null,
        'name' => 'Missing context objective',
        'goal' => 'Show blocked signature context',
        'locale' => 'en',
        'status' => 'active',
    ]);
    $legacy = phase3hOpportunity($objective, [
        'payload' => [
            'detector' => 'content_network_gaps',
            'topic' => 'Missing workspace context',
            'signals' => ['topic_keyword' => 'Missing workspace context'],
        ],
    ]);

    $signature = app(AgenticOpportunityActionSignatureService::class)
        ->forLegacyOpportunity($legacy, AgenticMarketingActionType::CreateArticle->value);

    expect($signature['signature'])->toBeNull()
        ->and($signature['blocked_reasons'])->toContain('missing_workspace_id');
});

it('inspects existing open actions and duplicate risks without mutating actions', function (): void {
    [, , , $objective] = phase3hContext('phase-3h-inspect');
    $legacy = phase3hOpportunity($objective);
    phase3hCanonicalOpportunity($legacy);
    $action = phase3hAction($legacy);
    $updatedAt = $action->updated_at?->toIso8601String();

    $result = app(AgenticOpportunityActionDedupeInspectionService::class)->inspect($legacy);

    expect($result['linked'])->toBeTrue()
        ->and($result['open_action_count'])->toBe(1)
        ->and($result['existing_actions_by_action_type'])->toHaveKey(AgenticMarketingActionType::CreateArticle->value)
        ->and($result['current_action_dedupe_keys'][0]['dedupe_hash'])->toBe($action->dedupe_hash)
        ->and($result['duplicate_risk_count'])->toBe(1)
        ->and($result['safe_future_canonical_action_candidate_count'])->toBe(0)
        ->and($action->refresh()->updated_at?->toIso8601String())->toBe($updatedAt)
        ->and(AgenticMarketingAction::query()->count())->toBe(1);
});

it('reports the diagnostics command without creating or mutating actions', function (): void {
    [, $workspace, , $objective] = phase3hContext('phase-3h-command');
    $legacy = phase3hOpportunity($objective);
    phase3hCanonicalOpportunity($legacy);
    phase3hAction($legacy);
    $legacyUpdatedAt = $legacy->updated_at?->toIso8601String();
    $actionCount = AgenticMarketingAction::query()->count();

    $this->artisan('mos:inspect-agentic-action-dedupe', [
        '--workspace' => (string) $workspace->id,
        '--limit' => 5,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Agentic action dedupe diagnostics.')
        ->expectsOutputToContain('inspected opportunities count: 1')
        ->expectsOutputToContain('linked canonical count: 1')
        ->expectsOutputToContain('duplicate action risks count: 1')
        ->expectsOutputToContain('signature samples:');

    expect($legacy->refresh()->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and(AgenticMarketingAction::query()->count())->toBe($actionCount);
});

it('leaves default Agentic action planning legacy-parented', function (): void {
    [, , , $objective] = phase3hContext('phase-3h-planner');
    $legacy = phase3hOpportunity($objective);
    phase3hCanonicalOpportunity($legacy);

    $summary = app(AgenticMarketingActionPlanner::class)->planForObjective($objective);
    $action = AgenticMarketingAction::query()->firstOrFail();

    expect($summary['created'])->toBe(1)
        ->and($action->opportunity_id)->toBe($legacy->id)
        ->and($action->objective_id)->toBe($objective->id)
        ->and($action->action_type)->toBe(AgenticMarketingActionType::CreateArticle->value);
});
