<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\RecommendedAction;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\ContentOpportunityRecommendedActionDedupeService;
use App\Services\Mos\Opportunity\ContentOpportunityRecommendedActionRepairService;
use App\Services\Mos\Opportunity\ContentOpportunityRecommendedActionSignature;
use App\Services\RecommendedActions\RecommendedActionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase2fContext(string $slug = 'phase-2f'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 2F '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 2F Workspace',
        'display_name' => 'Phase 2F Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 2F Site',
        'site_url' => 'https://phase-2f.test',
        'base_url' => 'https://phase-2f.test',
        'allowed_domains' => ['phase-2f.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$organization, $workspace, $site];
}

function phase2fContentOpportunity(array $overrides = []): ContentOpportunity
{
    [$organization, $workspace, $site] = phase2fContext('phase-2f-'.Str::lower(Str::random(6)));

    return ContentOpportunity::query()->create(array_merge([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => 'content_gap',
        'status' => ContentOpportunity::STATUS_OPEN,
        'title' => 'Prepare canonical action dedupe guide',
        'reasoning' => 'The linked records represent the same work.',
        'why_this_matters' => 'Duplicate actions would confuse approval flow.',
        'why_now' => 'Canonical migration is starting.',
        'angle' => 'Dedupe the action handoff.',
        'expected_impact' => 'high',
        'confidence_score' => 76,
        'urgency_score' => 64,
        'business_value_score' => 81,
        'priority_score' => 89,
        'suggested_cta' => 'Prepare the canonical plan',
        'source_signals' => [['type' => 'migration_blocker']],
        'normalized_payload' => ['candidate' => ['topic' => 'recommended action dedupe']],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function phase2fCanonicalOpportunity(ContentOpportunity $opportunity, array $overrides = []): Opportunity
{
    return Opportunity::factory()->create(array_merge([
        'organization_id' => $opportunity->organization_id,
        'workspace_id' => $opportunity->workspace_id,
        'client_site_id' => $opportunity->client_site_id,
        'content_opportunity_id' => $opportunity->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical action dedupe guide',
        'topic' => 'recommended action dedupe',
        'summary' => 'Canonical and legacy action sources should converge.',
        'recommended_actions' => [['title' => 'Review canonical action handoff']],
        'dedupe_hash' => $opportunity->dedupe_hash,
    ], $overrides));
}

function phase2fAction(array $overrides): RecommendedAction
{
    return RecommendedAction::query()->create(array_merge([
        'workspace_id' => $overrides['workspace_id'],
        'organization_id' => $overrides['organization_id'] ?? null,
        'source_group' => RecommendedAction::SOURCE_CONTENT_INTELLIGENCE,
        'action_type' => 'prepare_content_opportunity',
        'status' => RecommendedAction::STATUS_OPEN,
        'title' => 'Existing action',
        'summary' => 'Existing action summary.',
        'why_this_matters' => 'Existing action reason.',
        'expected_outcome' => 'Existing action outcome.',
        'what_argusly_will_do' => 'Existing action plan.',
        'what_requires_approval' => 'Approval is required.',
        'estimated_effort' => RecommendedAction::EFFORT_MEDIUM,
        'priority_score' => 70,
        'confidence_score' => 70,
        'expected_impact_score' => 70,
        'priority_label' => 'high',
        'confidence_label' => 'high',
        'expected_impact_label' => 'high',
        'visible_at' => now(),
    ], $overrides));
}

it('uses the same canonical-equivalent signature for linked legacy and canonical actions', function (): void {
    $legacy = phase2fContentOpportunity();
    $canonical = phase2fCanonicalOpportunity($legacy);

    $legacyAction = app(RecommendedActionEngine::class)->upsertFromSource($legacy);
    $canonicalAction = app(RecommendedActionEngine::class)->upsertFromSource($canonical);

    expect($legacyAction->source_signature)->toBe($canonicalAction->source_signature)
        ->and(RecommendedAction::query()->where('workspace_id', $legacy->workspace_id)->count())->toBe(1);
});

it('keeps legacy-only and canonical-only signatures stable', function (): void {
    $legacyOnly = phase2fContentOpportunity();
    $canonicalOnly = Opportunity::factory()->create([
        'organization_id' => $legacyOnly->organization_id,
        'workspace_id' => $legacyOnly->workspace_id,
        'client_site_id' => $legacyOnly->client_site_id,
        'content_opportunity_id' => null,
    ]);

    $signature = app(ContentOpportunityRecommendedActionSignature::class);
    $legacySignature = $signature->signature($legacyOnly, $legacyOnly->workspace, RecommendedAction::SOURCE_CONTENT_INTELLIGENCE, 'prepare_content_opportunity');
    $canonicalSignature = $signature->signature($canonicalOnly, $canonicalOnly->workspace, RecommendedAction::SOURCE_OPPORTUNITY, 'review_opportunity');

    expect($legacySignature)->not->toBe($canonicalSignature)
        ->and($legacySignature)->toBe(app(RecommendedActionEngine::class)->upsertFromSource($legacyOnly)->source_signature)
        ->and($canonicalSignature)->toBe(app(RecommendedActionEngine::class)->upsertFromSource($canonicalOnly)->source_signature);
});

it('detects old duplicate recommended actions for linked sources without mutating them', function (): void {
    $legacy = phase2fContentOpportunity();
    $canonical = phase2fCanonicalOpportunity($legacy);
    $signature = app(ContentOpportunityRecommendedActionSignature::class);
    $legacyPreviousSignature = $signature->legacySignature($legacy, $legacy->workspace, RecommendedAction::SOURCE_CONTENT_INTELLIGENCE);
    $canonicalPreviousSignature = $signature->legacySignature($canonical, $canonical->workspace, RecommendedAction::SOURCE_OPPORTUNITY);

    phase2fAction([
        'workspace_id' => $legacy->workspace_id,
        'organization_id' => $legacy->organization_id,
        'source_type' => ContentOpportunity::class,
        'source_id' => (string) $legacy->id,
        'source_signature' => $legacyPreviousSignature,
    ]);
    phase2fAction([
        'workspace_id' => $legacy->workspace_id,
        'organization_id' => $legacy->organization_id,
        'source_type' => Opportunity::class,
        'source_id' => (string) $canonical->id,
        'source_signature' => $canonicalPreviousSignature,
        'source_group' => RecommendedAction::SOURCE_OPPORTUNITY,
        'action_type' => 'review_opportunity',
    ]);

    $result = app(ContentOpportunityRecommendedActionDedupeService::class)->inspect($legacy);

    expect($result['linked'])->toBeTrue()
        ->and($result['existing_action_count'])->toBe(2)
        ->and($result['duplicate_count'])->toBe(1)
        ->and($result['safe_candidate_count'])->toBe(1)
        ->and(RecommendedAction::query()->where('workspace_id', $legacy->workspace_id)->count())->toBe(2);
});

it('selects a deterministic primary action for duplicate repair metadata', function (): void {
    $legacy = phase2fContentOpportunity();
    $canonical = phase2fCanonicalOpportunity($legacy);
    $signature = app(ContentOpportunityRecommendedActionSignature::class);
    $legacyPreviousSignature = $signature->legacySignature($legacy, $legacy->workspace, RecommendedAction::SOURCE_CONTENT_INTELLIGENCE);
    $canonicalPreviousSignature = $signature->legacySignature($canonical, $canonical->workspace, RecommendedAction::SOURCE_OPPORTUNITY);

    $canonicalAction = phase2fAction([
        'workspace_id' => $legacy->workspace_id,
        'organization_id' => $legacy->organization_id,
        'source_type' => Opportunity::class,
        'source_id' => (string) $canonical->id,
        'source_signature' => $canonicalPreviousSignature,
        'source_group' => RecommendedAction::SOURCE_OPPORTUNITY,
        'action_type' => 'review_opportunity',
        'status' => RecommendedAction::STATUS_DISMISSED,
    ]);
    $legacyAction = phase2fAction([
        'workspace_id' => $legacy->workspace_id,
        'organization_id' => $legacy->organization_id,
        'source_type' => ContentOpportunity::class,
        'source_id' => (string) $legacy->id,
        'source_signature' => $legacyPreviousSignature,
        'status' => RecommendedAction::STATUS_OPEN,
    ]);

    $result = app(ContentOpportunityRecommendedActionRepairService::class)->propose($legacy);

    expect($result['would_annotate'])->toBeTrue()
        ->and($result['primary_action_id'])->toBe($legacyAction->id)
        ->and($result['duplicate_action_ids'])->toContain($canonicalAction->id);
});

it('dry-run repair does not mutate recommended actions', function (): void {
    $legacy = phase2fContentOpportunity();
    $canonical = phase2fCanonicalOpportunity($legacy);
    $signature = app(ContentOpportunityRecommendedActionSignature::class);
    $legacyPreviousSignature = $signature->legacySignature($legacy, $legacy->workspace, RecommendedAction::SOURCE_CONTENT_INTELLIGENCE);
    $canonicalPreviousSignature = $signature->legacySignature($canonical, $canonical->workspace, RecommendedAction::SOURCE_OPPORTUNITY);

    $legacyAction = phase2fAction([
        'workspace_id' => $legacy->workspace_id,
        'organization_id' => $legacy->organization_id,
        'source_type' => ContentOpportunity::class,
        'source_id' => (string) $legacy->id,
        'source_signature' => $legacyPreviousSignature,
        'metadata' => ['existing' => 'kept'],
    ]);
    $canonicalAction = phase2fAction([
        'workspace_id' => $legacy->workspace_id,
        'organization_id' => $legacy->organization_id,
        'source_type' => Opportunity::class,
        'source_id' => (string) $canonical->id,
        'source_signature' => $canonicalPreviousSignature,
        'source_group' => RecommendedAction::SOURCE_OPPORTUNITY,
        'action_type' => 'review_opportunity',
    ]);

    $result = app(ContentOpportunityRecommendedActionRepairService::class)->propose($legacy);

    expect($result['would_annotate'])->toBeTrue()
        ->and($result['annotated_count'])->toBe(0)
        ->and($legacyAction->refresh()->metadata)->toBe(['existing' => 'kept'])
        ->and($canonicalAction->refresh()->metadata)->toBeNull()
        ->and(RecommendedAction::query()->where('workspace_id', $legacy->workspace_id)->count())->toBe(2);
});

it('apply annotates metadata only and preserves visible action state', function (): void {
    $legacy = phase2fContentOpportunity();
    $canonical = phase2fCanonicalOpportunity($legacy);
    $signature = app(ContentOpportunityRecommendedActionSignature::class);
    $legacyPreviousSignature = $signature->legacySignature($legacy, $legacy->workspace, RecommendedAction::SOURCE_CONTENT_INTELLIGENCE);
    $canonicalPreviousSignature = $signature->legacySignature($canonical, $canonical->workspace, RecommendedAction::SOURCE_OPPORTUNITY);

    $legacyAction = phase2fAction([
        'workspace_id' => $legacy->workspace_id,
        'organization_id' => $legacy->organization_id,
        'source_type' => ContentOpportunity::class,
        'source_id' => (string) $legacy->id,
        'source_signature' => $legacyPreviousSignature,
        'metadata' => ['existing' => 'kept'],
    ]);
    $canonicalAction = phase2fAction([
        'workspace_id' => $legacy->workspace_id,
        'organization_id' => $legacy->organization_id,
        'source_type' => Opportunity::class,
        'source_id' => (string) $canonical->id,
        'source_signature' => $canonicalPreviousSignature,
        'source_group' => RecommendedAction::SOURCE_OPPORTUNITY,
        'action_type' => 'review_opportunity',
        'status' => RecommendedAction::STATUS_COMPLETED,
    ]);

    $result = app(ContentOpportunityRecommendedActionRepairService::class)->annotate($legacy, 'test');

    $legacyAction->refresh();
    $canonicalAction->refresh();

    expect($result['annotated_count'])->toBe(2)
        ->and($legacyAction->source_signature)->toBe($legacyPreviousSignature)
        ->and($canonicalAction->source_signature)->toBe($canonicalPreviousSignature)
        ->and($legacyAction->status)->toBe(RecommendedAction::STATUS_OPEN)
        ->and($canonicalAction->status)->toBe(RecommendedAction::STATUS_COMPLETED)
        ->and($legacyAction->source_type)->toBe(ContentOpportunity::class)
        ->and($canonicalAction->source_type)->toBe(Opportunity::class)
        ->and(data_get($legacyAction->metadata, 'existing'))->toBe('kept')
        ->and(data_get($legacyAction->metadata, 'canonical_equivalence.duplicate_role'))->toBe('primary')
        ->and(data_get($canonicalAction->metadata, 'canonical_equivalence.duplicate_role'))->toBe('duplicate')
        ->and(data_get($canonicalAction->metadata, 'canonical_equivalence.repair_actor'))->toBe('test')
        ->and(data_get($canonicalAction->metadata, 'canonical_equivalence.canonical_equivalent_signature'))->toBe($result['canonical_equivalent_signature'])
        ->and(RecommendedAction::query()->where('workspace_id', $legacy->workspace_id)->count())->toBe(2);
});

it('reports dry-run command behaviour and does not change briefs or lifecycle statuses', function (): void {
    $legacy = phase2fContentOpportunity();
    phase2fCanonicalOpportunity($legacy);
    app(RecommendedActionEngine::class)->upsertFromSource($legacy);
    $updatedAt = $legacy->updated_at?->toIso8601String();

    $this->artisan('mos:dedupe-content-opportunity-actions', [
        '--workspace' => $legacy->workspace_id,
        '--limit' => 5,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('safe candidates');

    expect(Brief::query()->count())->toBe(0)
        ->and($legacy->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN)
        ->and($legacy->updated_at?->toIso8601String())->toBe($updatedAt);
});

it('command apply annotates duplicate metadata only', function (): void {
    $legacy = phase2fContentOpportunity();
    $canonical = phase2fCanonicalOpportunity($legacy);
    $signature = app(ContentOpportunityRecommendedActionSignature::class);
    $legacyPreviousSignature = $signature->legacySignature($legacy, $legacy->workspace, RecommendedAction::SOURCE_CONTENT_INTELLIGENCE);
    $canonicalPreviousSignature = $signature->legacySignature($canonical, $canonical->workspace, RecommendedAction::SOURCE_OPPORTUNITY);

    $legacyAction = phase2fAction([
        'workspace_id' => $legacy->workspace_id,
        'organization_id' => $legacy->organization_id,
        'source_type' => ContentOpportunity::class,
        'source_id' => (string) $legacy->id,
        'source_signature' => $legacyPreviousSignature,
    ]);
    $canonicalAction = phase2fAction([
        'workspace_id' => $legacy->workspace_id,
        'organization_id' => $legacy->organization_id,
        'source_type' => Opportunity::class,
        'source_id' => (string) $canonical->id,
        'source_signature' => $canonicalPreviousSignature,
        'source_group' => RecommendedAction::SOURCE_OPPORTUNITY,
        'action_type' => 'review_opportunity',
    ]);

    $this->artisan('mos:dedupe-content-opportunity-actions', [
        '--apply' => true,
        '--source-id' => $legacy->id,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('metadata only')
        ->expectsOutputToContain('annotated');

    expect(data_get($legacyAction->refresh()->metadata, 'canonical_equivalence.repair_status'))->toBe('annotated')
        ->and(data_get($canonicalAction->refresh()->metadata, 'canonical_equivalence.repair_status'))->toBe('annotated')
        ->and(RecommendedAction::query()->where('workspace_id', $legacy->workspace_id)->count())->toBe(2)
        ->and(Brief::query()->count())->toBe(0)
        ->and($legacy->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN);
});
