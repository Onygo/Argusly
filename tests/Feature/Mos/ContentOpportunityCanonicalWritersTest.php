<?php

use App\Enums\GrowthProgramStatus;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\ClientSite;
use App\Models\ContentOpportunity;
use App\Models\GrowthAsset;
use App\Models\GrowthAutopilotQueueItem;
use App\Models\GrowthProgram;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\RecommendedAction;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalAutopilotQueueWriter;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalGrowthAssetWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase2nContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 2N '.Str::random(6),
        'slug' => 'phase-2n-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 2N Workspace',
        'display_name' => 'Phase 2N Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 2N Site',
        'site_url' => 'https://phase-2n.test',
        'base_url' => 'https://phase-2n.test',
        'allowed_domains' => ['phase-2n.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$organization, $workspace, $site];
}

function phase2nContentOpportunity(?Workspace $workspace = null, ?ClientSite $site = null, array $overrides = []): ContentOpportunity
{
    if (! $workspace || ! $site) {
        [, $workspace, $site] = phase2nContext();
    }

    return ContentOpportunity::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => 'content_gap',
        'status' => ContentOpportunity::STATUS_OPEN,
        'freshness_status' => 'fresh',
        'title' => 'Build canonical growth writer page',
        'reasoning' => 'Canonical growth handoff needs guarded references.',
        'why_this_matters' => 'Duplicate execution would create noisy growth work.',
        'angle' => 'Canonical writer guardrails',
        'expected_impact' => 'high',
        'confidence_score' => 78,
        'urgency_score' => 68,
        'business_value_score' => 84,
        'priority_score' => 91,
        'source_signals' => [['type' => 'canonical_writer']],
        'normalized_payload' => ['candidate' => ['topic' => 'canonical writers']],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function phase2nCanonical(ContentOpportunity $opportunity, array $overrides = []): Opportunity
{
    return Opportunity::factory()->create(array_merge([
        'organization_id' => $opportunity->organization_id,
        'workspace_id' => $opportunity->workspace_id,
        'client_site_id' => $opportunity->client_site_id,
        'content_opportunity_id' => $opportunity->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical growth writer page',
        'topic' => 'canonical writers',
        'summary' => 'Canonical opportunity for guarded growth and autopilot writer support.',
        'recommended_actions' => [['title' => 'Review canonical writer support']],
        'priority_score' => 91,
        'dedupe_hash' => $opportunity->dedupe_hash,
    ], $overrides));
}

function phase2nGrowthProgram(Workspace $workspace): GrowthProgram
{
    return GrowthProgram::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'name' => 'Phase 2N Growth Program',
        'status' => GrowthProgramStatus::QUALIFIED->value,
        'score' => 80,
        'estimated_impact' => 80,
        'estimated_reach' => 8000,
        'estimated_ai_visibility_impact' => 70,
        'qualified_at' => now(),
    ]);
}

it('keeps canonical growth and autopilot writer flags disabled by default', function (): void {
    expect(config('features.mos_canonical_content_opportunity_growth_writer'))->toBeFalse()
        ->and(config('features.mos_canonical_content_opportunity_autopilot_writer'))->toBeFalse();
});

it('dry-runs a safe canonical growth asset candidate without creating records', function (): void {
    [, $workspace, $site] = phase2nContext();
    $legacy = phase2nContentOpportunity($workspace, $site);
    $canonical = phase2nCanonical($legacy);
    $program = phase2nGrowthProgram($workspace);

    $result = app(ContentOpportunityCanonicalGrowthAssetWriter::class)->dryRun($legacy, $canonical, $program);

    expect($result->safe)->toBeTrue()
        ->and($result->status)->toBe('would_create')
        ->and($result->metadata['source_evidence']['legacy_content_opportunity_id'])->toBe($legacy->id)
        ->and(GrowthAsset::query()->count())->toBe(0);
});

it('applies a canonical growth asset only when the feature flag is enabled', function (): void {
    config(['features.mos_canonical_content_opportunity_growth_writer' => true]);
    [, $workspace, $site] = phase2nContext();
    $legacy = phase2nContentOpportunity($workspace, $site);
    $canonical = phase2nCanonical($legacy);
    $program = phase2nGrowthProgram($workspace);

    $result = app(ContentOpportunityCanonicalGrowthAssetWriter::class)->apply($legacy, $canonical, $program);

    expect($result->applied)->toBeTrue()
        ->and($result->status)->toBe('created')
        ->and(GrowthAsset::query()->count())->toBe(1);

    $asset = GrowthAsset::query()->first();
    expect($asset->assetable_type)->toBe($canonical->getMorphClass())
        ->and($asset->assetable_id)->toBe($canonical->id)
        ->and($asset->role)->toBe(GrowthAsset::ROLE_OPPORTUNITY)
        ->and($asset->metadata['legacy_content_opportunity_id'])->toBe($legacy->id)
        ->and($asset->metadata['source_evidence']['canonical_opportunity_id'])->toBe($canonical->id);
});

it('does not rewrite existing legacy growth assets and blocks duplicate execution', function (): void {
    config(['features.mos_canonical_content_opportunity_growth_writer' => true]);
    [, $workspace, $site] = phase2nContext();
    $legacy = phase2nContentOpportunity($workspace, $site);
    $canonical = phase2nCanonical($legacy);
    $program = phase2nGrowthProgram($workspace);
    $legacyAsset = GrowthAsset::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'role' => GrowthAsset::ROLE_CONTENT_OPPORTUNITY,
        'assetable_type' => $legacy->getMorphClass(),
        'assetable_id' => $legacy->id,
        'status_at_link' => $legacy->status,
        'source_type' => 'legacy_test',
        'weight' => 1,
        'metadata' => ['legacy' => true],
    ]);
    $legacyUpdatedAt = $legacyAsset->updated_at?->toIso8601String();

    $result = app(ContentOpportunityCanonicalGrowthAssetWriter::class)->apply($legacy, $canonical, $program);

    expect($result->applied)->toBeFalse()
        ->and($result->duplicateExecutionRisks)->toContain('legacy_growth_asset_exists_for_program')
        ->and(GrowthAsset::query()->count())->toBe(1)
        ->and($legacyAsset->refresh()->metadata)->toBe(['legacy' => true])
        ->and($legacyAsset->updated_at?->toIso8601String())->toBe($legacyUpdatedAt);
});

it('dry-runs canonical autopilot queue candidates without creating recommended actions or queue items', function (): void {
    [, $workspace, $site] = phase2nContext();
    $legacy = phase2nContentOpportunity($workspace, $site);
    $canonical = phase2nCanonical($legacy);

    $result = app(ContentOpportunityCanonicalAutopilotQueueWriter::class)->dryRun($legacy, $canonical);

    expect($result->safe)->toBeTrue()
        ->and($result->status)->toBe('would_create')
        ->and($result->sourceSignature)->not->toBeNull()
        ->and($result->queueSignature)->not->toBeNull()
        ->and(RecommendedAction::query()->count())->toBe(0)
        ->and(GrowthAutopilotQueueItem::query()->count())->toBe(0);
});

it('applies canonical autopilot queue references through the existing queue upsert path', function (): void {
    config(['features.mos_canonical_content_opportunity_autopilot_writer' => true]);
    [, $workspace, $site] = phase2nContext();
    $legacy = phase2nContentOpportunity($workspace, $site);
    $canonical = phase2nCanonical($legacy);

    $result = app(ContentOpportunityCanonicalAutopilotQueueWriter::class)->apply($legacy, $canonical);

    expect($result->applied)->toBeTrue()
        ->and($result->status)->toBe('created')
        ->and(RecommendedAction::query()->count())->toBe(1)
        ->and(GrowthAutopilotQueueItem::query()->count())->toBe(1);

    $item = GrowthAutopilotQueueItem::query()->first();
    expect($item->source_type)->toBe($canonical->getMorphClass())
        ->and($item->source_id)->toBe($canonical->id)
        ->and($item->metadata['legacy_content_opportunity_id'])->toBe($legacy->id)
        ->and($item->metadata['canonical_opportunity_id'])->toBe($canonical->id)
        ->and($item->metadata['source_evidence']['legacy_content_opportunity_id'])->toBe($legacy->id);
});

it('prevents duplicate autopilot queue work when a legacy queue item already exists', function (): void {
    config(['features.mos_canonical_content_opportunity_autopilot_writer' => true]);
    [, $workspace, $site] = phase2nContext();
    $legacy = phase2nContentOpportunity($workspace, $site);
    $canonical = phase2nCanonical($legacy);

    GrowthAutopilotQueueItem::query()->create([
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'source_type' => $legacy->getMorphClass(),
        'source_id' => $legacy->id,
        'source_signature' => 'phase-2n-legacy-queue',
        'source_group' => RecommendedAction::SOURCE_CONTENT_INTELLIGENCE,
        'status' => GrowthAutopilotQueueItem::STATUS_NEEDS_APPROVAL,
        'opportunity' => 'Build canonical growth writer page',
        'recommended_action' => 'Review legacy queue item',
        'queued_at' => now(),
    ]);

    $result = app(ContentOpportunityCanonicalAutopilotQueueWriter::class)->apply($legacy, $canonical);

    expect($result->applied)->toBeFalse()
        ->and($result->duplicateExecutionRisks)->toContain('legacy_autopilot_queue_item_exists')
        ->and(RecommendedAction::query()->count())->toBe(0)
        ->and(GrowthAutopilotQueueItem::query()->count())->toBe(1);
});
