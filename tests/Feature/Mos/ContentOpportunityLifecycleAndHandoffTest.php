<?php

use App\Enums\OpportunityStatus;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\ContentOpportunityBriefHandoffPlanner;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalBriefActionPlanner;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalBriefWriter;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalLifecycleSyncService;
use App\Services\Mos\Opportunity\ContentOpportunityLifecycleMap;
use App\Services\Mos\Opportunity\ContentOpportunityRecommendedActionSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase2eContext(string $slug = 'phase-2e'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 2E '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 2E Workspace',
        'display_name' => 'Phase 2E Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 2E Site',
        'site_url' => 'https://phase-2e.test',
        'base_url' => 'https://phase-2e.test',
        'allowed_domains' => ['phase-2e.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$organization, $workspace, $site];
}

function phase2eContentOpportunity(array $overrides = []): ContentOpportunity
{
    [$organization, $workspace, $site] = phase2eContext('phase-2e-'.Str::lower(Str::random(6)));

    return ContentOpportunity::query()->create(array_merge([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => 'content_gap',
        'status' => ContentOpportunity::STATUS_OPEN,
        'title' => 'Create a canonical handoff guide',
        'reasoning' => 'Searchers need a clear implementation guide.',
        'why_this_matters' => 'The topic can convert high-intent visitors.',
        'why_now' => 'Competitors are publishing now.',
        'target_audience' => 'Marketing operators',
        'funnel_stage' => 'consideration',
        'primary_search_intent' => 'implementation',
        'angle' => 'Operational handoff angle',
        'expected_impact' => 'high',
        'confidence_score' => 75,
        'urgency_score' => 68,
        'business_value_score' => 82,
        'priority_score' => 91,
        'related_entities' => ['canonical opportunity', 'brief handoff'],
        'recommended_internal_links' => [['anchor_text' => 'content planning']],
        'localization_recommendation' => ['priority_locales' => ['en']],
        'suggested_cta' => 'Create the brief',
        'suggested_schema' => 'Article',
        'source_signals' => [['type' => 'search_gap']],
        'normalized_payload' => ['candidate' => ['topic' => 'canonical brief handoff']],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function phase2eCanonical(ContentOpportunity $opportunity, array $overrides = []): Opportunity
{
    return Opportunity::factory()->create(array_merge([
        'organization_id' => $opportunity->organization_id,
        'workspace_id' => $opportunity->workspace_id,
        'client_site_id' => $opportunity->client_site_id,
        'content_opportunity_id' => $opportunity->id,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical brief handoff guide',
        'topic' => 'canonical brief handoff',
        'recommended_actions' => [['title' => 'Create canonical execution plan']],
        'evidence' => [['type' => 'canonical_signal']],
    ], $overrides));
}

it('maps legacy lifecycle statuses to canonical statuses and reports unmapped statuses', function (): void {
    $map = app(ContentOpportunityLifecycleMap::class);

    expect($map->legacyToCanonical(ContentOpportunity::STATUS_OPEN))->toBe(OpportunityStatus::OPEN)
        ->and($map->legacyToCanonical(ContentOpportunity::STATUS_PLANNED))->toBe(OpportunityStatus::PLANNED)
        ->and($map->canonicalToLegacy(OpportunityStatus::DISMISSED))->toBe(ContentOpportunity::STATUS_DISMISSED)
        ->and($map->canonicalToLegacy(OpportunityStatus::APPROVED))->toBeNull()
        ->and($map->unmappedLegacyStatus('paused'))->toBe('paused')
        ->and($map->unmappedCanonicalStatus(OpportunityStatus::ACTIONED))->toBe(OpportunityStatus::ACTIONED->value);
});

it('detects linked lifecycle conflicts without changing records', function (): void {
    $opportunity = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_OPEN]);
    $canonical = phase2eCanonical($opportunity, ['status' => OpportunityStatus::PLANNED]);
    $legacyUpdatedAt = $opportunity->updated_at?->toIso8601String();
    $canonicalUpdatedAt = $canonical->updated_at?->toIso8601String();

    $comparison = app(ContentOpportunityLifecycleMap::class)->compare($opportunity, $canonical);

    expect($comparison['conflict'])->toBeTrue()
        ->and($comparison['aligned'])->toBeFalse()
        ->and($comparison['mapped_canonical_status'])->toBe(OpportunityStatus::OPEN->value)
        ->and($opportunity->refresh()->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and($canonical->refresh()->updated_at?->toIso8601String())->toBe($canonicalUpdatedAt);
});

it('reports lifecycle command output for aligned conflicts unmapped statuses and missing canonical links', function (): void {
    $aligned = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_OPEN]);
    phase2eCanonical($aligned, ['status' => OpportunityStatus::OPEN]);

    $conflict = phase2eContentOpportunity([
        'organization_id' => $aligned->organization_id,
        'workspace_id' => $aligned->workspace_id,
        'client_site_id' => $aligned->client_site_id,
        'status' => ContentOpportunity::STATUS_PLANNED,
    ]);
    phase2eCanonical($conflict, ['status' => OpportunityStatus::OPEN]);

    $unmapped = phase2eContentOpportunity([
        'organization_id' => $aligned->organization_id,
        'workspace_id' => $aligned->workspace_id,
        'client_site_id' => $aligned->client_site_id,
        'status' => 'paused',
    ]);
    phase2eCanonical($unmapped, ['status' => OpportunityStatus::APPROVED]);

    phase2eContentOpportunity([
        'organization_id' => $aligned->organization_id,
        'workspace_id' => $aligned->workspace_id,
        'client_site_id' => $aligned->client_site_id,
        'status' => ContentOpportunity::STATUS_OPEN,
    ]);

    $this->artisan('mos:compare-content-opportunity-lifecycle', [
        '--workspace' => $aligned->workspace_id,
        '--limit' => 10,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only lifecycle comparison')
        ->expectsOutputToContain('conflict')
        ->expectsOutputToContain('unmapped_legacy_status')
        ->expectsOutputToContain('missing_canonical_link');
});

it('dry-runs legacy-to-canonical lifecycle sync without mutations', function (): void {
    $opportunity = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_PLANNED]);
    $canonical = phase2eCanonical($opportunity, ['status' => OpportunityStatus::OPEN]);
    $legacyUpdatedAt = $opportunity->updated_at?->toIso8601String();
    $canonicalUpdatedAt = $canonical->updated_at?->toIso8601String();

    $result = app(ContentOpportunityCanonicalLifecycleSyncService::class)
        ->dryRun($opportunity, $canonical, ContentOpportunityCanonicalLifecycleSyncService::DIRECTION_LEGACY_TO_CANONICAL);

    expect($result->safe)->toBeTrue()
        ->and($result->applied)->toBeFalse()
        ->and($result->status)->toBe('would_update')
        ->and($result->conflict)->toBeTrue()
        ->and($result->desiredCanonicalStatus)->toBe(OpportunityStatus::PLANNED->value)
        ->and($opportunity->refresh()->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and($canonical->refresh()->status)->toBe(OpportunityStatus::OPEN)
        ->and($canonical->updated_at?->toIso8601String())->toBe($canonicalUpdatedAt);
});

it('applies legacy-to-canonical lifecycle sync for safe linked records only', function (): void {
    $opportunity = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_PLANNED]);
    $canonical = phase2eCanonical($opportunity, ['status' => OpportunityStatus::OPEN]);

    $result = app(ContentOpportunityCanonicalLifecycleSyncService::class)
        ->apply($opportunity, $canonical, ContentOpportunityCanonicalLifecycleSyncService::DIRECTION_LEGACY_TO_CANONICAL);

    expect($result->applied)->toBeTrue()
        ->and($result->status)->toBe('updated')
        ->and($opportunity->refresh()->status)->toBe(ContentOpportunity::STATUS_PLANNED)
        ->and($canonical->refresh()->status)->toBe(OpportunityStatus::PLANNED);
});

it('dry-runs canonical-to-legacy lifecycle sync without mutations', function (): void {
    $opportunity = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_OPEN]);
    $canonical = phase2eCanonical($opportunity, ['status' => OpportunityStatus::DISMISSED]);

    $result = app(ContentOpportunityCanonicalLifecycleSyncService::class)
        ->dryRun($opportunity, $canonical, ContentOpportunityCanonicalLifecycleSyncService::DIRECTION_CANONICAL_TO_LEGACY);

    expect($result->safe)->toBeTrue()
        ->and($result->applied)->toBeFalse()
        ->and($result->status)->toBe('would_update')
        ->and($result->desiredLegacyStatus)->toBe(ContentOpportunity::STATUS_DISMISSED)
        ->and($opportunity->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN)
        ->and($canonical->refresh()->status)->toBe(OpportunityStatus::DISMISSED);
});

it('blocks canonical-only statuses from reverse lifecycle sync', function (): void {
    $opportunity = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_OPEN]);
    $canonical = phase2eCanonical($opportunity, ['status' => OpportunityStatus::APPROVED]);

    $result = app(ContentOpportunityCanonicalLifecycleSyncService::class)
        ->dryRun($opportunity, $canonical, ContentOpportunityCanonicalLifecycleSyncService::DIRECTION_CANONICAL_TO_LEGACY);

    expect($result->safe)->toBeFalse()
        ->and($result->status)->toBe('blocked')
        ->and($result->blockedReasons)->toContain('blocked_canonical_only_status')
        ->and($opportunity->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN);
});

it('reports unmapped legacy statuses and missing links as blocked sync rows', function (): void {
    $unmapped = phase2eContentOpportunity(['status' => 'paused']);
    $canonical = phase2eCanonical($unmapped, ['status' => OpportunityStatus::OPEN]);
    $missing = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_OPEN]);

    $service = app(ContentOpportunityCanonicalLifecycleSyncService::class);
    $unmappedResult = $service->dryRun($unmapped, $canonical, ContentOpportunityCanonicalLifecycleSyncService::DIRECTION_LEGACY_TO_CANONICAL);
    $missingResult = $service->dryRun($missing, null, ContentOpportunityCanonicalLifecycleSyncService::DIRECTION_LEGACY_TO_CANONICAL);

    expect($unmappedResult->safe)->toBeFalse()
        ->and($unmappedResult->blockedReasons)->toContain('unmapped_legacy_status')
        ->and($missingResult->safe)->toBeFalse()
        ->and($missingResult->blockedReasons)->toContain('missing_canonical_link');
});

it('blocks lifecycle sync when linked pair integrity is unsafe', function (): void {
    $opportunity = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_PLANNED]);
    $other = phase2eContentOpportunity([
        'organization_id' => $opportunity->organization_id,
        'workspace_id' => $opportunity->workspace_id,
        'client_site_id' => $opportunity->client_site_id,
    ]);
    $canonical = phase2eCanonical($other, [
        'organization_id' => $opportunity->organization_id,
        'workspace_id' => $opportunity->workspace_id,
        'client_site_id' => $opportunity->client_site_id,
        'status' => OpportunityStatus::OPEN,
    ]);

    $result = app(ContentOpportunityCanonicalLifecycleSyncService::class)
        ->sync($opportunity, $canonical, ContentOpportunityCanonicalLifecycleSyncService::DIRECTION_LEGACY_TO_CANONICAL, true);

    expect($result->safe)->toBeFalse()
        ->and($result->applied)->toBeFalse()
        ->and($result->blockedReasons)->toContain('canonical_legacy_link_mismatch')
        ->and($canonical->refresh()->status)->toBe(OpportunityStatus::OPEN);
});

it('reports lifecycle sync command dry-run summaries without updating records', function (): void {
    $opportunity = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_PLANNED]);
    $canonical = phase2eCanonical($opportunity, ['status' => OpportunityStatus::OPEN]);

    $this->artisan('mos:sync-content-opportunity-lifecycle', [
        '--workspace' => $opportunity->workspace_id,
        '--direction' => ContentOpportunityCanonicalLifecycleSyncService::DIRECTION_LEGACY_TO_CANONICAL,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Dry-run mode lifecycle sync')
        ->expectsOutputToContain('would-update rows')
        ->expectsOutputToContain('conflicts');

    expect($canonical->refresh()->status)->toBe(OpportunityStatus::OPEN);
});

it('applies lifecycle sync command only for safe linked records', function (): void {
    $opportunity = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_ARCHIVED]);
    $canonical = phase2eCanonical($opportunity, ['status' => OpportunityStatus::OPEN]);
    $blocked = phase2eContentOpportunity([
        'organization_id' => $opportunity->organization_id,
        'workspace_id' => $opportunity->workspace_id,
        'client_site_id' => $opportunity->client_site_id,
        'status' => 'paused',
    ]);
    phase2eCanonical($blocked, ['status' => OpportunityStatus::OPEN]);

    $this->artisan('mos:sync-content-opportunity-lifecycle', [
        '--apply' => true,
        '--workspace' => $opportunity->workspace_id,
        '--direction' => ContentOpportunityCanonicalLifecycleSyncService::DIRECTION_LEGACY_TO_CANONICAL,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Apply mode lifecycle sync')
        ->expectsOutputToContain('updated rows')
        ->expectsOutputToContain('unmapped_legacy_status');

    expect($canonical->refresh()->status)->toBe(OpportunityStatus::ARCHIVED)
        ->and(Opportunity::query()->where('content_opportunity_id', $blocked->id)->firstOrFail()->status)->toBe(OpportunityStatus::OPEN);
});

it('keeps lifecycle sync feature flag disabled by default', function (): void {
    expect(config('features.mos_canonical_content_opportunity_lifecycle_sync'))->toBeFalse();
});

it('plans a safe canonical brief handoff without creating briefs or changing statuses', function (): void {
    $opportunity = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_OPEN]);
    $canonical = phase2eCanonical($opportunity);

    $plan = app(ContentOpportunityCanonicalBriefActionPlanner::class)->plan($opportunity);

    expect($plan->safe)->toBeTrue()
        ->and($plan->safetyStatus)->toBe('safe')
        ->and($plan->canonicalOpportunityId)->toBe($canonical->id)
        ->and($plan->legacyContentOpportunityId)->toBe($opportunity->id)
        ->and($plan->workspaceId)->toBe($opportunity->workspace_id)
        ->and($plan->clientSiteId)->toBe($opportunity->client_site_id)
        ->and($plan->proposedActionType)->toBe(ContentOpportunityBriefHandoffPlanner::ACTION_TYPE)
        ->and($plan->proposedCtaLabel)->toBe('Review canonical opportunity')
        ->and($plan->proposedCtaRoute)->toBe(route('app.opportunities.show', $canonical))
        ->and($plan->proposedSourceLink)->toBe(route('app.agentic-marketing.content-opportunities.index', [
            'workspace_id' => $opportunity->workspace_id,
            'client_site_id' => $opportunity->client_site_id,
            'status' => $opportunity->status,
        ]))
        ->and($plan->recommendedBriefTitle)->toBe('Canonical brief handoff guide')
        ->and($plan->primaryKeyword)->toBe('canonical brief handoff')
        ->and($plan->audience)->toBe('Marketing operators')
        ->and($plan->funnelStage)->toBe('consideration')
        ->and($plan->intent)->toBe('implementation')
        ->and($plan->legacyRequiredFields['primary_keyword'])->toBe('canonical brief handoff')
        ->and($plan->legacyRequiredFields['client_refs']['content_opportunity_id'])->toBe($opportunity->id)
        ->and($plan->legacyRequiredFields['client_refs']['canonical_opportunity_id'])->toBe($canonical->id)
        ->and($plan->targetContext['client_site_id'])->toBe($opportunity->client_site_id)
        ->and($plan->missingFields)->toBe([])
        ->and(Brief::query()->count())->toBe(0)
        ->and($opportunity->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN);
});

it('uses the phase 2f canonical-equivalent source signature for canonical brief actions', function (): void {
    $opportunity = phase2eContentOpportunity();
    $canonical = phase2eCanonical($opportunity, ['dedupe_hash' => $opportunity->dedupe_hash]);
    $signature = app(ContentOpportunityRecommendedActionSignature::class);

    $plan = app(ContentOpportunityCanonicalBriefActionPlanner::class)->plan($opportunity);

    expect($plan->proposedSourceSignature)->toBe($signature->signature($canonical, $canonical->workspace, 'opportunity', 'review_opportunity'))
        ->and($plan->proposedSourceSignature)->toBe($signature->signature($opportunity, $opportunity->workspace, 'content_intelligence', 'prepare_content_opportunity'));
});

it('reports missing fields when canonical link and site context are absent', function (): void {
    $opportunity = phase2eContentOpportunity([
        'client_site_id' => null,
        'title' => '',
        'normalized_payload' => [],
        'source_signals' => [],
        'reasoning' => null,
        'why_this_matters' => null,
        'why_now' => null,
        'target_audience' => null,
        'funnel_stage' => null,
        'primary_search_intent' => null,
    ]);

    $plan = app(ContentOpportunityCanonicalBriefActionPlanner::class)->plan($opportunity);

    expect($plan->safe)->toBeFalse()
        ->and($plan->safetyStatus)->toBe('blocked')
        ->and($plan->missingFields)->toContain('canonical_opportunity_id', 'client_site_id', 'title', 'primary_keyword', 'source_evidence')
        ->and($plan->proposedCtaRoute)->toBe(route('app.agentic-marketing.content-opportunities.index', [
            'workspace_id' => $opportunity->workspace_id,
            'status' => $opportunity->status,
        ]));
});

it('keeps the brief handoff command dry-run only', function (): void {
    $opportunity = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_OPEN]);
    phase2eCanonical($opportunity, ['status' => OpportunityStatus::OPEN]);
    $legacyUpdatedAt = $opportunity->updated_at?->toIso8601String();
    $canonicalUpdatedAt = Opportunity::query()->firstOrFail()->updated_at?->toIso8601String();

    $this->artisan('mos:plan-content-opportunity-brief-handoff', [
        '--workspace' => $opportunity->workspace_id,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Dry-run only')
        ->expectsOutputToContain('safe');

    expect(Brief::query()->count())->toBe(0)
        ->and($opportunity->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN)
        ->and($opportunity->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and(Opportunity::query()->firstOrFail()->updated_at?->toIso8601String())->toBe($canonicalUpdatedAt);
});

it('reports canonical brief action readiness from the new diagnostics command without mutations', function (): void {
    $linked = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_OPEN]);
    phase2eCanonical($linked, ['status' => OpportunityStatus::OPEN]);
    $unlinked = phase2eContentOpportunity([
        'organization_id' => $linked->organization_id,
        'workspace_id' => $linked->workspace_id,
        'client_site_id' => null,
        'title' => '',
        'normalized_payload' => [],
        'source_signals' => [],
        'reasoning' => null,
        'why_this_matters' => null,
        'why_now' => null,
    ]);
    $linkedUpdatedAt = $linked->updated_at?->toIso8601String();
    $unlinkedUpdatedAt = $unlinked->updated_at?->toIso8601String();
    $canonicalUpdatedAt = Opportunity::query()->firstOrFail()->updated_at?->toIso8601String();

    $this->artisan('mos:plan-content-opportunity-brief-actions', [
        '--workspace' => $linked->workspace_id,
        '--limit' => 10,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only canonical brief action planning')
        ->expectsOutputToContain('safe canonical brief action candidates')
        ->expectsOutputToContain('blocked candidates')
        ->expectsOutputToContain('proposed CTA route')
        ->expectsOutputToContain('proposed source link')
        ->expectsOutputToContain('proposed source signature')
        ->expectsOutputToContain('source_evidence');

    expect(Brief::query()->count())->toBe(0)
        ->and($linked->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN)
        ->and($unlinked->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN)
        ->and($linked->updated_at?->toIso8601String())->toBe($linkedUpdatedAt)
        ->and($unlinked->updated_at?->toIso8601String())->toBe($unlinkedUpdatedAt)
        ->and(Opportunity::query()->firstOrFail()->updated_at?->toIso8601String())->toBe($canonicalUpdatedAt);
});

it('dry-runs a safe canonical brief writer candidate without mutations', function (): void {
    $opportunity = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_OPEN]);
    $canonical = phase2eCanonical($opportunity, ['status' => OpportunityStatus::OPEN]);
    $legacyUpdatedAt = $opportunity->updated_at?->toIso8601String();
    $canonicalUpdatedAt = $canonical->updated_at?->toIso8601String();

    $result = app(ContentOpportunityCanonicalBriefWriter::class)
        ->dryRun($opportunity, $canonical, $opportunity->site, 'single');

    expect($result->safe)->toBeTrue()
        ->and($result->applied)->toBeFalse()
        ->and($result->status)->toBe('would_create')
        ->and($result->canonicalOpportunityId)->toBe($canonical->id)
        ->and($result->legacyContentOpportunityId)->toBe($opportunity->id)
        ->and($result->payload['source'])->toBe('content_opportunity')
        ->and($result->payload['title'])->toBe('Canonical brief handoff guide')
        ->and($result->payload['primary_keyword'])->toBe('canonical brief handoff')
        ->and(data_get($result->payload, 'client_refs.content_opportunity_id'))->toBe($opportunity->id)
        ->and(data_get($result->payload, 'client_refs.canonical_opportunity_id'))->toBe($canonical->id)
        ->and(Brief::query()->count())->toBe(0)
        ->and($opportunity->refresh()->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and($canonical->refresh()->updated_at?->toIso8601String())->toBe($canonicalUpdatedAt);
});

it('blocks canonical brief writing when the canonical link is missing', function (): void {
    $opportunity = phase2eContentOpportunity();

    $result = app(ContentOpportunityCanonicalBriefWriter::class)
        ->dryRun($opportunity, null, $opportunity->site, 'single');

    expect($result->safe)->toBeFalse()
        ->and($result->status)->toBe('blocked')
        ->and($result->missingFields)->toContain('canonical_opportunity_id')
        ->and($result->blockedReasons)->toContain('canonical_opportunity_id')
        ->and(Brief::query()->count())->toBe(0);
});

it('blocks canonical brief writing when required site title keyword or evidence is missing', function (): void {
    $opportunity = phase2eContentOpportunity([
        'client_site_id' => null,
        'title' => '',
        'normalized_payload' => [],
        'source_signals' => [],
        'reasoning' => null,
        'why_this_matters' => null,
        'why_now' => null,
    ]);
    $canonical = phase2eCanonical($opportunity, [
        'client_site_id' => null,
        'title' => '',
        'topic' => '',
        'evidence' => [],
    ]);

    $result = app(ContentOpportunityCanonicalBriefWriter::class)
        ->dryRun($opportunity, $canonical, null, 'single');

    expect($result->safe)->toBeFalse()
        ->and($result->missingFields)->toContain('client_site_id', 'title', 'primary_keyword', 'source_evidence')
        ->and($result->blockedReasons)->toContain('client_site_id', 'title', 'primary_keyword', 'source_evidence')
        ->and(Brief::query()->count())->toBe(0);
});

it('applies the canonical brief writer with legacy-compatible fields and traceability without status updates', function (): void {
    $opportunity = phase2eContentOpportunity(['status' => ContentOpportunity::STATUS_OPEN]);
    $canonical = phase2eCanonical($opportunity, ['status' => OpportunityStatus::OPEN]);
    $legacyUpdatedAt = $opportunity->updated_at?->toIso8601String();
    $canonicalUpdatedAt = $canonical->updated_at?->toIso8601String();

    $result = app(ContentOpportunityCanonicalBriefWriter::class)
        ->apply($opportunity, $canonical, $opportunity->site, 'single');

    $brief = Brief::query()->firstOrFail();

    expect($result->applied)->toBeTrue()
        ->and($result->status)->toBe('created')
        ->and($brief->source)->toBe('content_opportunity')
        ->and($brief->status)->toBe('draft')
        ->and($brief->title)->toBe('Canonical brief handoff guide')
        ->and($brief->primary_keyword)->toBe('canonical brief handoff')
        ->and($brief->audience)->toBe('Marketing operators')
        ->and($brief->target_audience)->toBe('Marketing operators')
        ->and($brief->funnel_stage)->toBe('consideration')
        ->and($brief->search_intent)->toBe('implementation')
        ->and($brief->unique_angle)->toBe('Operational handoff angle')
        ->and($brief->key_points)->toContain('Searchers need a clear implementation guide.')
        ->and($brief->call_to_action)->toBe('Create the brief')
        ->and($brief->desired_length_min)->toBe(1000)
        ->and($brief->desired_length_max)->toBe(1500)
        ->and(data_get($brief->client_refs, 'content_opportunity.id'))->toBe($opportunity->id)
        ->and(data_get($brief->client_refs, 'content_opportunity_id'))->toBe($opportunity->id)
        ->and(data_get($brief->client_refs, 'canonical_opportunity_id'))->toBe($canonical->id)
        ->and(data_get($brief->client_refs, 'mode'))->toBe('single')
        ->and(data_get($brief->client_refs, 'source_signature'))->not->toBeEmpty()
        ->and($opportunity->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN)
        ->and($opportunity->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and($canonical->refresh()->status)->toBe(OpportunityStatus::OPEN)
        ->and($canonical->updated_at?->toIso8601String())->toBe($canonicalUpdatedAt);
});

it('detects duplicate canonical brief creation attempts', function (): void {
    $opportunity = phase2eContentOpportunity();
    $canonical = phase2eCanonical($opportunity);
    $writer = app(ContentOpportunityCanonicalBriefWriter::class);

    $first = $writer->apply($opportunity, $canonical, $opportunity->site, 'single');
    $second = $writer->dryRun($opportunity->refresh(), $canonical->refresh(), $opportunity->site, 'single');

    expect($first->status)->toBe('created')
        ->and($second->safe)->toBeFalse()
        ->and($second->status)->toBe('blocked')
        ->and($second->duplicateRisk)->toBeTrue()
        ->and($second->blockedReasons)->toContain('duplicate_brief')
        ->and(Brief::query()->count())->toBe(1);
});

it('reports the guarded canonical brief writer command dry-run output', function (): void {
    $opportunity = phase2eContentOpportunity();
    phase2eCanonical($opportunity);

    $this->artisan('mos:create-canonical-content-opportunity-brief', [
        '--workspace' => $opportunity->workspace_id,
        '--source-id' => $opportunity->id,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Dry-run mode')
        ->expectsOutputToContain('would-create brief: 1')
        ->expectsOutputToContain('duplicate brief risk: 0');

    expect(Brief::query()->count())->toBe(0);
});

it('applies the guarded canonical brief writer command for safe records only', function (): void {
    $opportunity = phase2eContentOpportunity();
    phase2eCanonical($opportunity);

    $this->artisan('mos:create-canonical-content-opportunity-brief', [
        '--apply' => true,
        '--workspace' => $opportunity->workspace_id,
        '--source-id' => $opportunity->id,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Apply mode')
        ->expectsOutputToContain('created briefs: 1')
        ->expectsOutputToContain('duplicate brief risk: 0');

    expect(Brief::query()->count())->toBe(1)
        ->and($opportunity->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN);
});

it('does not change the visible content opportunity brief route or controller owner', function (): void {
    $route = Route::getRoutes()->getByName('app.agentic-marketing.content-opportunities.brief.create');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain('POST')
        ->and($route->getActionName())->toBe('App\Http\Controllers\App\AppContentOpportunityController@createBrief');
});
