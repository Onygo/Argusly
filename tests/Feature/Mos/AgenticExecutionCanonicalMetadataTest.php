<?php

use App\Enums\AgenticMarketingActionType;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionPipeline;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use App\Services\AgenticMarketing\ExecutionPipeline\OpportunityExecutionPipelineService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticExecutionCanonicalMetadataResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3kContext(string $slug = 'phase-3k'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3K '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3K Workspace',
        'display_name' => 'Phase 3K Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3K Site',
        'site_url' => 'https://phase-3k.test',
        'base_url' => 'https://phase-3k.test',
        'allowed_domains' => ['phase-3k.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3K objective',
        'goal' => 'Inspect additive execution metadata',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3kOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3K execution metadata',
        'type' => 'content_network',
        'priority_score' => 89,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Execution canonical metadata',
            'reasoning' => 'Future execution rows need additive canonical trace context.',
            'recommendation' => 'Create the additive metadata guide.',
            'primary_search_intent' => 'implementation',
            'target_audience' => 'content teams',
            'angle' => 'Keep execution legacy-owned while adding trace context.',
            'suggested_cta' => 'Review the metadata plan',
            'suggested_schema' => 'Article',
            'signals' => [
                'cluster_id' => 'phase-3k',
                'cluster_name' => 'Execution canonical metadata',
                'topic_keyword' => 'Execution canonical metadata',
                'gap_type' => 'missing_pillar',
            ],
            'score_explanation' => [
                'summary' => 'Metadata can be additive without parent migration.',
                'impact_score' => 86,
                'confidence_score' => 80,
                'effort_score' => 42,
            ],
        ],
    ], $overrides));
}

function phase3kCanonical(AgenticMarketingOpportunity $legacy, array $overrides = []): Opportunity
{
    $legacy->loadMissing('objective');

    return Opportunity::factory()->create(array_merge([
        'organization_id' => $legacy->objective->organization_id,
        'workspace_id' => $legacy->objective->workspace_id,
        'client_site_id' => $legacy->objective->client_site_id,
        'agentic_marketing_opportunity_id' => $legacy->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical Phase 3K execution metadata',
        'topic' => 'Execution canonical metadata',
        'summary' => 'Linked canonical context for future execution rows.',
        'recommended_actions' => [['title' => 'Keep execution legacy-owned']],
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

function phase3kAction(AgenticMarketingOpportunity $opportunity, array $overrides = []): AgenticMarketingAction
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
            'title' => 'Execution canonical metadata',
            'planning' => [
                'planner' => AgenticMarketingActionPlanner::class,
                'source_opportunity_type' => (string) $opportunity->type,
            ],
        ],
    ], $overrides));
}

it('resolves additive metadata for exactly one safe canonical bridge', function (): void {
    [, , , $objective] = phase3kContext('phase-3k-safe');
    $legacy = phase3kOpportunity($objective);
    $canonical = phase3kCanonical($legacy);

    $result = app(AgenticExecutionCanonicalMetadataResolver::class)->resolve($legacy, 'pipeline');

    expect($result['safe'])->toBeTrue()
        ->and($result['metadata']['canonical_opportunity_id'])->toBe($canonical->id)
        ->and($result['metadata']['legacy_agentic_marketing_opportunity_id'])->toBe($legacy->id)
        ->and($result['metadata']['metadata_version'])->toBe(AgenticExecutionCanonicalMetadataResolver::METADATA_VERSION)
        ->and($result['metadata']['bridge_source'])->toBe('opportunities.agentic_marketing_opportunity_id');
});

it('blocks resolver output when canonical bridge context is unsafe', function (): void {
    [, $workspace, , $objective] = phase3kContext('phase-3k-blockers');
    $legacy = phase3kOpportunity($objective);

    expect(app(AgenticExecutionCanonicalMetadataResolver::class)->resolve($legacy, 'pipeline')['blocked_reasons'])
        ->toContain('missing_safe_canonical_bridge');

    phase3kCanonical($legacy, ['dedupe_hash' => 'phase-3k-one-'.Str::random(8)]);
    phase3kCanonical($legacy, ['dedupe_hash' => 'phase-3k-two-'.Str::random(8)]);

    expect(app(AgenticExecutionCanonicalMetadataResolver::class)->resolve($legacy, 'pipeline')['blocked_reasons'])
        ->toContain('multiple_canonical_opportunities_linked_to_agentic_row');

    $otherWorkspace = Workspace::query()->create([
        'organization_id' => $workspace->organization_id,
        'name' => 'Phase 3K Other Workspace',
    ]);
    $mismatch = phase3kOpportunity($objective, [
        'title' => 'Workspace mismatch',
        'payload' => [
            'topic' => 'Workspace mismatch metadata',
            'signals' => [
                'cluster_id' => 'phase-3k-mismatch',
                'topic_keyword' => 'Workspace mismatch metadata',
            ],
        ],
    ]);
    phase3kCanonical($mismatch, ['workspace_id' => $otherWorkspace->id]);

    expect(app(AgenticExecutionCanonicalMetadataResolver::class)->resolve($mismatch, 'pipeline')['blocked_reasons'])
        ->toContain('canonical_bridge_workspace_mismatch');
});

it('blocks resolver output when Phase 3I or Phase 3J reports unsafe continuity', function (): void {
    [, , , $objective] = phase3kContext('phase-3k-continuity');
    $legacy = phase3kOpportunity($objective);
    phase3kCanonical($legacy);
    AgenticMarketingExecutionPipeline::query()->create([
        'organization_id' => $objective->organization_id,
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'mode' => 'manual',
        'status' => 'running',
        'current_stage' => 'asset_generation',
        'approval_status' => 'pending',
        'publishing_readiness' => 'not_ready',
    ]);

    $result = app(AgenticExecutionCanonicalMetadataResolver::class)->resolve($legacy, 'pipeline');

    expect($result['safe'])->toBeFalse()
        ->and($result['blocked_reasons'])->toContain('phase_3i_execution_continuity_blocked')
        ->and($result['blocked_reasons'])->toContain('canonical_parent_only_lookup_would_miss_execution_pipelines');

    [, , , $secondObjective] = phase3kContext('phase-3k-lifecycle');
    $ambiguous = phase3kOpportunity($secondObjective, ['status' => 'completed']);
    phase3kCanonical($ambiguous, ['status' => OpportunityStatus::ACTIONED]);

    expect(app(AgenticExecutionCanonicalMetadataResolver::class)->resolve($ambiguous, 'pipeline')['blocked_reasons'])
        ->toContain('phase_3j_lifecycle_status_ambiguous');
});

it('does not write metadata to new execution rows while the feature flag is disabled', function (): void {
    config(['features.mos_agentic_execution_canonical_metadata_writer' => false]);
    [, , , $objective] = phase3kContext('phase-3k-disabled');
    $legacy = phase3kOpportunity($objective);
    phase3kCanonical($legacy);

    $pipeline = app(OpportunityExecutionPipelineService::class)->prepare($legacy);

    expect(data_get($pipeline->input, 'canonical_opportunity_context'))->toBeNull()
        ->and(data_get($pipeline->assets->first()->payload, 'canonical_opportunity_context'))->toBeNull()
        ->and(Brief::query()->where('client_refs->opportunity_id', $legacy->id)->first()?->client_refs)->not->toHaveKey('canonical_opportunity_id')
        ->and(Draft::query()->where('meta->opportunity_id', $legacy->id)->first()?->meta)->not->toHaveKey('canonical_opportunity_id');
});

it('writes additive metadata to newly-created pipeline assets briefs drafts and action runs when enabled', function (): void {
    config(['features.mos_agentic_execution_canonical_metadata_writer' => true]);
    [, , , $objective] = phase3kContext('phase-3k-enabled');
    $legacy = phase3kOpportunity($objective);
    $canonical = phase3kCanonical($legacy);

    $pipeline = app(OpportunityExecutionPipelineService::class)->prepare($legacy);

    expect(data_get($pipeline->input, 'canonical_opportunity_context.canonical_opportunity_id'))->toBe($canonical->id)
        ->and(data_get($pipeline->assets->first()->payload, 'canonical_opportunity_context.canonical_opportunity_id'))->toBe($canonical->id)
        ->and(Brief::query()->where('client_refs->opportunity_id', $legacy->id)->first()?->client_refs['canonical_opportunity_id'])->toBe($canonical->id)
        ->and(Draft::query()->where('meta->opportunity_id', $legacy->id)->first()?->meta['canonical_opportunity_id'])->toBe($canonical->id);

    [, , , $actionObjective] = phase3kContext('phase-3k-action-run');
    $actionLegacy = phase3kOpportunity($actionObjective);
    $actionCanonical = phase3kCanonical($actionLegacy);
    $action = phase3kAction($actionLegacy);
    $run = AgenticActionRun::query()->where('action_id', $action->id)->firstOrFail();

    expect(data_get($run->input_snapshot, 'canonical_opportunity_id'))->toBe($actionCanonical->id)
        ->and(data_get($run->input_snapshot, 'canonical_opportunity_context.legacy_agentic_marketing_opportunity_id'))->toBe($actionLegacy->id);
});

it('does not backfill or rewrite existing execution rows', function (): void {
    config(['features.mos_agentic_execution_canonical_metadata_writer' => false]);
    [, , , $objective] = phase3kContext('phase-3k-existing');
    $legacy = phase3kOpportunity($objective);
    phase3kCanonical($legacy);
    $pipeline = app(OpportunityExecutionPipelineService::class)->prepare($legacy);
    $action = phase3kAction($legacy);
    $run = AgenticActionRun::query()->where('action_id', $action->id)->firstOrFail();
    $pipelineUpdatedAt = $pipeline->updated_at?->toIso8601String();
    $assetUpdatedAt = $pipeline->assets->first()->updated_at?->toIso8601String();
    $runUpdatedAt = $run->updated_at?->toIso8601String();

    config(['features.mos_agentic_execution_canonical_metadata_writer' => true]);

    $this->artisan('mos:write-agentic-execution-canonical-metadata')
        ->assertSuccessful()
        ->expectsOutputToContain('Historical backfill is intentionally unsupported.');

    expect($pipeline->refresh()->updated_at?->toIso8601String())->toBe($pipelineUpdatedAt)
        ->and($pipeline->assets->first()->refresh()->updated_at?->toIso8601String())->toBe($assetUpdatedAt)
        ->and($run->refresh()->updated_at?->toIso8601String())->toBe($runUpdatedAt)
        ->and(data_get($pipeline->input, 'canonical_opportunity_context'))->toBeNull()
        ->and(data_get($run->input_snapshot, 'canonical_opportunity_context'))->toBeNull();
});

it('keeps routes and Agentic action planner selection legacy-owned', function (): void {
    config(['features.mos_agentic_execution_canonical_metadata_writer' => true]);
    [, , , $objective] = phase3kContext('phase-3k-routes');
    $legacy = phase3kOpportunity($objective);
    $canonical = phase3kCanonical($legacy);

    expect(route('app.agentic-marketing.opportunities.execution.show', $legacy))
        ->toContain($legacy->id)
        ->not->toContain($canonical->id);

    $actions = app(AgenticMarketingActionPlanner::class)->planForOpportunity($legacy);

    expect($actions)->not->toBeEmpty()
        ->and(collect($actions)->pluck('opportunity_id')->filter()->unique()->values()->all())->not->toContain($canonical->id)
        ->and(AgenticMarketingAction::query()->where('objective_id', $objective->id)->get()->pluck('payload')->map(fn (array $payload): mixed => data_get($payload, 'planning.planner'))->filter()->unique()->values()->all())->toBe([AgenticMarketingActionPlanner::class])
        ->and(Opportunity::query()->whereKey($canonical->id)->first()?->recommended_actions)->toBe([['title' => 'Keep execution legacy-owned']]);
});

it('reports metadata planning diagnostics without writing records', function (): void {
    [, $workspace, , $objective] = phase3kContext('phase-3k-command');
    $legacy = phase3kOpportunity($objective);
    phase3kCanonical($legacy);
    $legacyUpdatedAt = $legacy->updated_at?->toIso8601String();

    $this->artisan('mos:plan-agentic-execution-canonical-metadata', [
        '--workspace' => (string) $workspace->id,
        '--limit' => 5,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Agentic execution canonical metadata diagnostics.')
        ->expectsOutputToContain('inspected count: 1')
        ->expectsOutputToContain('metadata safe count: 1')
        ->expectsOutputToContain('target field samples:');

    expect($legacy->refresh()->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and(AgenticMarketingExecutionPipeline::query()->count())->toBe(0);
});
