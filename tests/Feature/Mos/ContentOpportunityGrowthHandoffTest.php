<?php

use App\Enums\GrowthProgramStatus;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Enums\ProgrammaticPatternType;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ContentOpportunity;
use App\Models\GrowthAsset;
use App\Models\GrowthAutopilotQueueItem;
use App\Models\GrowthProgram;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\ProgrammaticOpportunity;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\ContentOpportunityGrowthHandoffPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase2hContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 2H '.Str::random(6),
        'slug' => 'phase-2h-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 2H Workspace',
        'display_name' => 'Phase 2H Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 2H Site',
        'site_url' => 'https://phase-2h.test',
        'base_url' => 'https://phase-2h.test',
        'allowed_domains' => ['phase-2h.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$organization, $workspace, $site];
}

function phase2hContentOpportunity(?Workspace $workspace = null, ?ClientSite $site = null, array $overrides = []): ContentOpportunity
{
    if (! $workspace || ! $site) {
        [, $workspace, $site] = phase2hContext();
    }

    return ContentOpportunity::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => 'programmatic_growth',
        'status' => ContentOpportunity::STATUS_OPEN,
        'freshness_status' => 'fresh',
        'title' => 'Build comparison page cluster',
        'reasoning' => 'Searchers compare alternatives before conversion.',
        'angle' => 'Programmatic comparison expansion',
        'expected_impact' => 'high',
        'confidence_score' => 77,
        'urgency_score' => 70,
        'business_value_score' => 85,
        'priority_score' => 93,
        'source_signals' => [['type' => 'comparison_gap']],
        'normalized_payload' => ['candidate' => ['topic' => 'comparison pages']],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function phase2hCanonical(ContentOpportunity $opportunity, array $overrides = []): Opportunity
{
    return Opportunity::factory()->create(array_merge([
        'organization_id' => $opportunity->organization_id,
        'workspace_id' => $opportunity->workspace_id,
        'client_site_id' => $opportunity->client_site_id,
        'content_opportunity_id' => $opportunity->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical comparison page cluster',
        'topic' => 'comparison pages',
        'summary' => 'Canonical opportunity for growth handoff planning.',
        'priority_score' => 93,
        'dedupe_hash' => $opportunity->dedupe_hash,
    ], $overrides));
}

function phase2hGrowthProgram(Workspace $workspace): GrowthProgram
{
    return GrowthProgram::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'name' => 'Phase 2H Growth Program',
        'status' => GrowthProgramStatus::QUALIFIED->value,
        'score' => 80,
        'estimated_impact' => 80,
        'estimated_reach' => 8000,
        'estimated_ai_visibility_impact' => 70,
        'qualified_at' => now(),
    ]);
}

it('plans linked growth handoff references without changing records', function (): void {
    [, $workspace, $site] = phase2hContext();
    $opportunity = phase2hContentOpportunity($workspace, $site);
    $canonical = phase2hCanonical($opportunity);
    $program = phase2hGrowthProgram($workspace);

    GrowthAsset::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'role' => GrowthAsset::ROLE_CONTENT_OPPORTUNITY,
        'assetable_type' => $opportunity->getMorphClass(),
        'assetable_id' => $opportunity->id,
        'status_at_link' => $opportunity->status,
        'source_type' => 'test',
        'weight' => 1,
        'metadata' => ['phase' => '2h'],
    ]);

    ProgrammaticOpportunity::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'source_type' => $opportunity->getMorphClass(),
        'source_id' => $opportunity->id,
        'pattern_type' => ProgrammaticPatternType::COMPARISON_PAGE,
        'base_topic' => 'comparison pages',
        'variable_axis' => 'competitor_or_product',
        'example_variables' => ['Product A', 'Product B'],
        'estimated_variants_count' => 20,
        'scale_score' => 70,
        'status' => ProgrammaticOpportunity::STATUS_DETECTED,
        'detected_at' => now(),
    ]);

    GrowthAutopilotQueueItem::query()->create([
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'source_type' => $opportunity->getMorphClass(),
        'source_id' => $opportunity->id,
        'source_signature' => 'phase-2h-legacy-queue',
        'source_group' => 'content_intelligence',
        'status' => GrowthAutopilotQueueItem::STATUS_NEEDS_APPROVAL,
        'opportunity' => 'Build comparison page cluster',
        'recommended_action' => 'Review content opportunity',
        'queued_at' => now(),
    ]);

    $legacyUpdatedAt = $opportunity->updated_at?->toIso8601String();
    $canonicalUpdatedAt = $canonical->updated_at?->toIso8601String();

    $plan = app(ContentOpportunityGrowthHandoffPlanner::class)->plan($opportunity);

    expect($plan->legacyContentOpportunityId)->toBe($opportunity->id)
        ->and($plan->canonicalOpportunityId)->toBe($canonical->id)
        ->and($plan->growthAssetReferences)->toHaveCount(1)
        ->and($plan->programmaticOpportunityReferences)->toHaveCount(1)
        ->and($plan->autopilotQueueReferences)->toHaveCount(1)
        ->and($plan->duplicateExecutionRisks)->toBe([])
        ->and($plan->safe)->toBeTrue()
        ->and($plan->recommendedFutureReferenceStrategy[0])->toContain('content_opportunity')
        ->and($opportunity->refresh()->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and($canonical->refresh()->updated_at?->toIso8601String())->toBe($canonicalUpdatedAt);
});

it('reports legacy fallback when no canonical link exists', function (): void {
    $opportunity = phase2hContentOpportunity();

    $plan = app(ContentOpportunityGrowthHandoffPlanner::class)->plan($opportunity);

    expect($plan->canonicalOpportunityId)->toBeNull()
        ->and($plan->safe)->toBeFalse()
        ->and($plan->missingFields)->toContain('canonical_opportunity_id')
        ->and($plan->recommendedFutureReferenceStrategy)->toContain('Create or repair one canonical Opportunity link before moving growth consumers.');
});

it('reports duplicate execution risk when legacy and canonical execution references coexist', function (): void {
    [, $workspace, $site] = phase2hContext();
    $opportunity = phase2hContentOpportunity($workspace, $site);
    $canonical = phase2hCanonical($opportunity);
    $program = phase2hGrowthProgram($workspace);

    foreach ([[$opportunity, GrowthAsset::ROLE_CONTENT_OPPORTUNITY], [$canonical, GrowthAsset::ROLE_OPPORTUNITY]] as [$source, $role]) {
        GrowthAsset::query()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'growth_program_id' => $program->id,
            'role' => $role,
            'assetable_type' => $source->getMorphClass(),
            'assetable_id' => $source->id,
            'status_at_link' => 'open',
            'source_type' => 'test',
            'weight' => 1,
        ]);
    }

    ProgrammaticOpportunity::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'source_type' => $canonical->getMorphClass(),
        'source_id' => $canonical->id,
        'pattern_type' => ProgrammaticPatternType::COMPARISON_PAGE,
        'base_topic' => 'comparison pages',
        'variable_axis' => 'competitor_or_product',
        'estimated_variants_count' => 20,
        'scale_score' => 70,
        'status' => ProgrammaticOpportunity::STATUS_DETECTED,
        'detected_at' => now(),
    ]);

    GrowthAutopilotQueueItem::query()->create([
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'source_type' => $opportunity->getMorphClass(),
        'source_id' => $opportunity->id,
        'source_signature' => 'phase-2h-legacy-queue-risk',
        'source_group' => 'content_intelligence',
        'status' => GrowthAutopilotQueueItem::STATUS_NEEDS_APPROVAL,
        'opportunity' => 'Legacy queue',
        'recommended_action' => 'Review legacy',
    ]);

    GrowthAutopilotQueueItem::query()->create([
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'source_type' => $canonical->getMorphClass(),
        'source_id' => $canonical->id,
        'source_signature' => 'phase-2h-canonical-queue-risk',
        'source_group' => 'opportunity',
        'status' => GrowthAutopilotQueueItem::STATUS_NEEDS_APPROVAL,
        'opportunity' => 'Canonical queue',
        'recommended_action' => 'Review canonical',
    ]);

    $plan = app(ContentOpportunityGrowthHandoffPlanner::class)->plan($opportunity);

    expect($plan->safe)->toBeFalse()
        ->and($plan->duplicateExecutionRisks)->toContain(
            'growth_asset_legacy_and_canonical_reference',
            'autopilot_queue_legacy_and_canonical_reference',
            'same_growth_program_dual_asset_reference',
        );
});

it('keeps the diagnostics command read-only and reports planning output', function (): void {
    $opportunity = phase2hContentOpportunity();
    phase2hCanonical($opportunity);
    $legacyUpdatedAt = $opportunity->updated_at?->toIso8601String();
    $growthAssets = GrowthAsset::query()->count();
    $queueItems = GrowthAutopilotQueueItem::query()->count();
    $briefs = Brief::query()->count();

    $this->artisan('mos:plan-content-opportunity-growth-handoff', [
        '--workspace' => $opportunity->workspace_id,
        '--limit' => 5,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only growth handoff planning')
        ->expectsOutputToContain('safe future handoff candidates');

    expect(GrowthAsset::query()->count())->toBe($growthAssets)
        ->and(GrowthAutopilotQueueItem::query()->count())->toBe($queueItems)
        ->and(Brief::query()->count())->toBe($briefs)
        ->and($opportunity->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN)
        ->and($opportunity->updated_at?->toIso8601String())->toBe($legacyUpdatedAt);
});
