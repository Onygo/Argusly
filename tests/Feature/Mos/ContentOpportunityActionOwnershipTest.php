<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\ClientSite;
use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\RecommendedAction;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalActionOwnershipResolver;
use App\Services\Mos\Opportunity\ContentOpportunityRecommendedActionSignature;
use App\Services\RecommendedActions\RecommendedActionEngine;
use App\Services\RecommendedActions\RecommendedActionMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase2oContext(string $slug = 'phase-2o'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 2O '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 2O Workspace',
        'display_name' => 'Phase 2O Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 2O Site',
        'site_url' => 'https://phase-2o.test',
        'base_url' => 'https://phase-2o.test',
        'allowed_domains' => ['phase-2o.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return compact('organization', 'workspace', 'site');
}

function phase2oContentOpportunity(array $overrides = []): ContentOpportunity
{
    $context = phase2oContext('phase-2o-'.Str::lower(Str::random(6)));

    return ContentOpportunity::query()->create(array_merge([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'type' => 'content_gap',
        'status' => ContentOpportunity::STATUS_OPEN,
        'title' => 'Prepare canonical action ownership guide',
        'reasoning' => 'The linked canonical opportunity is ready for CTA migration.',
        'why_this_matters' => 'Action ownership must stay traceable during migration.',
        'angle' => 'Move the CTA behind a flag.',
        'expected_impact' => 'strategic',
        'confidence_score' => 76,
        'urgency_score' => 64,
        'business_value_score' => 81,
        'priority_score' => 89,
        'suggested_cta' => 'Review canonical action ownership',
        'source_signals' => [['type' => 'migration_blocker']],
        'normalized_payload' => ['candidate' => ['topic' => 'action ownership']],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function phase2oCanonicalOpportunity(ContentOpportunity $legacy, array $overrides = []): Opportunity
{
    return Opportunity::factory()->create(array_merge([
        'organization_id' => $legacy->organization_id,
        'workspace_id' => $legacy->workspace_id,
        'client_site_id' => $legacy->client_site_id,
        'content_opportunity_id' => $legacy->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical action ownership guide',
        'topic' => 'action ownership',
        'summary' => 'Canonical opportunity review should own the future CTA.',
        'recommended_actions' => [['title' => 'Review canonical CTA ownership']],
        'dedupe_hash' => $legacy->dedupe_hash,
    ], $overrides));
}

function phase2oAction(array $overrides): RecommendedAction
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

it('keeps the action ownership feature flag disabled by default', function (): void {
    expect(config('features.mos_canonical_content_opportunity_action_ownership'))->toBeFalse();
});

it('returns legacy fallback ownership when the flag is disabled and no canonical link exists', function (): void {
    $legacy = phase2oContentOpportunity();

    $result = app(ContentOpportunityCanonicalActionOwnershipResolver::class)->resolve($legacy, featureEnabled: false);

    expect($result['ownership_status'])->toBe('legacy')
        ->and($result['canonical_owner_id'])->toBeNull()
        ->and($result['cta_route'])->toBe($result['fallback_route'])
        ->and($result['fallback_route'])->toContain('content-opportunities');
});

it('reports linked records as canonical-ready while keeping fallback routes when the flag is disabled', function (): void {
    $legacy = phase2oContentOpportunity();
    $canonical = phase2oCanonicalOpportunity($legacy);

    $result = app(ContentOpportunityCanonicalActionOwnershipResolver::class)->resolve($legacy, featureEnabled: false);

    expect($result['ownership_status'])->toBe('canonical-ready')
        ->and($result['canonical_owner_id'])->toBe($canonical->id)
        ->and($result['cta_route'])->toBe($result['fallback_route'])
        ->and($result['source_link'])->toBe($result['fallback_route']);
});

it('blocks canonical action ownership when the flag is enabled but the canonical link is missing', function (): void {
    $legacy = phase2oContentOpportunity();

    $result = app(ContentOpportunityCanonicalActionOwnershipResolver::class)->resolve($legacy, featureEnabled: true);

    expect($result['ownership_status'])->toBe('blocked')
        ->and($result['blocked_reasons'])->toContain('missing_canonical_link')
        ->and($result['cta_route'])->toBe($result['fallback_route']);
});

it('uses duplicate repair metadata to choose the display action without mutating statuses', function (): void {
    $legacy = phase2oContentOpportunity();
    $canonical = phase2oCanonicalOpportunity($legacy);
    $signature = app(ContentOpportunityRecommendedActionSignature::class);
    $legacyPreviousSignature = $signature->legacySignature($legacy, $legacy->workspace, RecommendedAction::SOURCE_CONTENT_INTELLIGENCE);
    $canonicalPreviousSignature = $signature->legacySignature($canonical, $canonical->workspace, RecommendedAction::SOURCE_OPPORTUNITY);
    $legacyAction = phase2oAction([
        'workspace_id' => $legacy->workspace_id,
        'organization_id' => $legacy->organization_id,
        'source_type' => ContentOpportunity::class,
        'source_id' => (string) $legacy->id,
        'source_signature' => $legacyPreviousSignature,
        'status' => RecommendedAction::STATUS_COMPLETED,
        'metadata' => ['canonical_equivalence' => ['duplicate_role' => 'duplicate', 'repair_status' => 'annotated']],
    ]);
    $canonicalAction = phase2oAction([
        'workspace_id' => $legacy->workspace_id,
        'organization_id' => $legacy->organization_id,
        'source_type' => Opportunity::class,
        'source_id' => (string) $canonical->id,
        'source_signature' => $canonicalPreviousSignature,
        'source_group' => RecommendedAction::SOURCE_OPPORTUNITY,
        'action_type' => 'review_opportunity',
        'metadata' => ['canonical_equivalence' => ['duplicate_role' => 'primary', 'repair_status' => 'annotated']],
    ]);

    $result = app(ContentOpportunityCanonicalActionOwnershipResolver::class)->resolve($legacy, featureEnabled: true);

    expect($result['ownership_status'])->toBe('canonical-active')
        ->and($result['display_action_id'])->toBe($canonicalAction->id)
        ->and($result['primary_recommended_action_id'])->toBe($canonicalAction->id)
        ->and($result['duplicate_recommended_action_ids'])->toContain($legacyAction->id)
        ->and($result['duplicate_metadata_status'])->toBe('annotated')
        ->and($legacyAction->refresh()->status)->toBe(RecommendedAction::STATUS_COMPLETED)
        ->and($canonicalAction->refresh()->status)->toBe(RecommendedAction::STATUS_OPEN);
});

it('keeps mapper output on legacy CTA and without ownership metadata when the flag is disabled', function (): void {
    config(['features.mos_canonical_content_opportunity_action_ownership' => false]);
    $legacy = phase2oContentOpportunity();
    phase2oCanonicalOpportunity($legacy);

    $payload = app(RecommendedActionMapper::class)->map($legacy);

    expect($payload['primary_cta_label'])->toBe('Open content opportunities')
        ->and($payload['primary_cta_url'])->toBe(route('app.agentic-marketing.content-opportunities.index', ['workspace_id' => $legacy->workspace_id]))
        ->and(data_get($payload, 'metadata.canonical_action_ownership'))->toBeNull();
});

it('points mapper output at the canonical CTA when the flag is enabled and ownership is safe', function (): void {
    config(['features.mos_canonical_content_opportunity_action_ownership' => true]);
    $legacy = phase2oContentOpportunity();
    $canonical = phase2oCanonicalOpportunity($legacy);

    $payload = app(RecommendedActionMapper::class)->map($legacy);

    expect($payload['primary_cta_label'])->toBe('Review action')
        ->and($payload['primary_cta_url'])->toBe(route('app.opportunities.show', $canonical))
        ->and(data_get($payload, 'metadata.canonical_action_ownership.ownership_status'))->toBe('canonical-active')
        ->and(data_get($payload, 'metadata.canonical_action_ownership.canonical_owner_id'))->toBe($canonical->id)
        ->and(data_get($payload, 'metadata.canonical_action_ownership.legacy_source_id'))->toBe($legacy->id);
});

it('does not create duplicate actions when canonical action ownership is enabled', function (): void {
    config(['features.mos_canonical_content_opportunity_action_ownership' => true]);
    $legacy = phase2oContentOpportunity();
    $canonical = phase2oCanonicalOpportunity($legacy);

    app(RecommendedActionEngine::class)->upsertFromSource($legacy);
    app(RecommendedActionEngine::class)->upsertFromSource($canonical);

    expect(RecommendedAction::query()->where('workspace_id', $legacy->workspace_id)->count())->toBe(1);
});

it('reports action ownership diagnostics without mutating records', function (): void {
    $legacy = phase2oContentOpportunity();
    phase2oCanonicalOpportunity($legacy);
    app(RecommendedActionEngine::class)->upsertFromSource($legacy);
    $updatedAt = $legacy->updated_at?->toIso8601String();

    $this->artisan('mos:inspect-content-opportunity-action-ownership', [
        '--source-id' => $legacy->id,
        '--limit' => 5,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only action ownership inspection')
        ->expectsOutputToContain('canonical-ready actions')
        ->expectsOutputToContain('proposed CTA route');

    expect($legacy->refresh()->updated_at?->toIso8601String())->toBe($updatedAt)
        ->and(RecommendedAction::query()->where('workspace_id', $legacy->workspace_id)->count())->toBe(1);
});
