<?php

use App\Enums\OpportunityStatus;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Http\Middleware\EnsureEmailCodeVerified;
use App\Http\Middleware\EnsureUserApproved;
use App\Http\Middleware\EnsureUserHasOrganization;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\CompanyIntelligenceProfile;
use App\Models\CompetitorContentOpportunity;
use App\Models\Content;
use App\Models\ContentOpportunity;
use App\Models\ContentOpportunityRun;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CompanyIntelligence\CompanyIntelligenceNormalizer;
use App\Services\ContentOpportunityEngine\ContentOpportunityEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeContentOpportunityEngineScope(): array
{
    $organization = Organization::query()->create([
        'name' => 'Opportunity Engine Org',
        'slug' => 'opportunity-engine-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);
    $workspace = Workspace::query()->create([
        'name' => 'Opportunity Engine Workspace',
        'organization_id' => $organization->id,
    ]);
    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Opportunity Site',
        'site_url' => 'https://opportunity.example.com',
        'base_url' => 'https://opportunity.example.com',
        'allowed_domains' => ['opportunity.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    CompanyIntelligenceProfile::query()->create(app(CompanyIntelligenceNormalizer::class)->persistencePayload([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'brand_key' => 'primary',
        'company_name' => 'Argusly',
        'company_description' => 'Agentic marketing and AI visibility platform.',
        'market_category' => 'Agentic marketing',
        'positioning' => 'Autonomous content planning for AI search.',
        'uvp' => 'Turns intelligence into content opportunities.',
        'products_services' => ['AI visibility tracking', 'Content opportunity engine'],
        'buyer_roles' => ['marketers', 'founders'],
        'primary_topics' => ['AI visibility', 'content opportunity engine'],
        'target_entities' => ['Agentic Marketing', 'AEO', 'Content Intelligence'],
        'strategic_keywords' => ['AI visibility platform'],
        'query_intents' => ['comparison', 'implementation'],
        'status' => CompanyIntelligenceProfile::STATUS_ACTIVE,
        'is_default' => true,
    ]));

    Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'AI visibility basics',
        'primary_keyword' => 'AI visibility',
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'content_health_score' => 52,
        'aeo_score' => 48,
        'ai_visibility_score' => 40,
    ]);

    CompetitorContentOpportunity::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => 'comparison_page',
        'status' => 'open',
        'title' => 'Build a comparison page around AI visibility platforms',
        'topic' => 'AI visibility platforms',
        'query_intent' => 'comparison',
        'funnel_stage' => 'bofu',
        'recommended_format' => 'comparison_page',
        'priority_score' => 88,
        'confidence_score' => 80,
        'impact_score' => 90,
        'effort_score' => 45,
        'attackable_angle' => 'Compare implementation depth and AI search outcomes.',
        'reason' => 'Competitors target comparison demand.',
        'dedupe_hash' => hash('sha256', 'competitor-opportunity'),
        'last_seen_at' => now(),
    ]);

    return [$organization, $workspace, $site];
}

function makeRouteContentOpportunity(Organization $organization, Workspace $workspace, ClientSite $site, array $overrides = []): ContentOpportunity
{
    return ContentOpportunity::query()->create(array_merge([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => 'content_gap',
        'status' => ContentOpportunity::STATUS_OPEN,
        'title' => 'Route migration opportunity',
        'reasoning' => 'Searchers need a clear route migration guide.',
        'why_this_matters' => 'It keeps the visible route safe during migration.',
        'why_now' => 'Canonical brief creation is ready for a guarded rollout.',
        'competitor_pressure' => 'Competitors are publishing migration playbooks.',
        'ai_visibility_opportunity' => 'The article can answer operational migration questions.',
        'target_audience' => 'Marketing operators',
        'funnel_stage' => 'consideration',
        'primary_search_intent' => 'implementation',
        'angle' => 'Explain the safe route migration path.',
        'expected_impact' => 'high',
        'confidence_score' => 80,
        'urgency_score' => 70,
        'business_value_score' => 82,
        'priority_score' => 91,
        'related_entities' => ['canonical brief writer', 'route migration'],
        'recommended_internal_links' => [['anchor_text' => 'brief migration']],
        'localization_recommendation' => ['priority_locales' => ['en']],
        'suggested_cta' => 'Create the brief',
        'suggested_schema' => 'Article',
        'source_signals' => [['type' => 'route_migration']],
        'normalized_payload' => ['candidate' => ['topic' => 'route migration']],
        'dedupe_hash' => hash('sha256', 'route-migration-'.Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

it('generates persisted net-new content opportunities from intelligence inputs', function () {
    [, $workspace, $site] = makeContentOpportunityEngineScope();

    $run = app(ContentOpportunityEngine::class)->run($workspace, (string) $site->id, ['source_type' => 'test']);

    expect($run)->toBeInstanceOf(ContentOpportunityRun::class)
        ->and($run->status)->toBe('completed')
        ->and($run->created_count)->toBeGreaterThan(0)
        ->and(ContentOpportunity::query()->where('workspace_id', $workspace->id)->count())->toBeGreaterThan(0)
        ->and(ContentOpportunity::query()->where('type', 'comparison_page')->exists())->toBeTrue()
        ->and(ContentOpportunity::query()->where('type', 'campaign_cluster')->exists())->toBeTrue();

    $opportunity = ContentOpportunity::query()->where('type', 'comparison_page')->firstOrFail();
    expect($opportunity->why_this_matters)->not->toBeEmpty()
        ->and($opportunity->why_now)->not->toBeEmpty()
        ->and($opportunity->competitor_pressure)->not->toBeEmpty()
        ->and($opportunity->ai_visibility_opportunity)->not->toBeEmpty()
        ->and($opportunity->recommended_internal_links)->toBeArray()
        ->and($opportunity->normalized_payload['schema'])->toBe('content_opportunity_engine.v1');
});

it('generates starter opportunities from workspace context when intelligence inputs are empty', function () {
    [, $workspace, $site] = makeContentOpportunityEngineScope();
    CompanyIntelligenceProfile::query()->where('workspace_id', $workspace->id)->delete();
    CompetitorContentOpportunity::query()->where('workspace_id', $workspace->id)->delete();
    Content::query()->where('workspace_id', $workspace->id)->delete();

    $run = app(ContentOpportunityEngine::class)->run($workspace, (string) $site->id, ['source_type' => 'test']);
    $opportunity = ContentOpportunity::query()->where('workspace_id', $workspace->id)->firstOrFail();

    expect($run->status)->toBe('completed')
        ->and($run->created_count)->toBeGreaterThan(0)
        ->and(ContentOpportunity::query()->where('workspace_id', $workspace->id)->count())->toBeGreaterThan(0)
        ->and($opportunity->source_signals['source'])->toBe('workspace_fallback')
        ->and(ContentOpportunity::query()->where('type', 'campaign_cluster')->exists())->toBeTrue();
});

it('deduplicates and refreshes existing open content opportunities', function () {
    [, $workspace, $site] = makeContentOpportunityEngineScope();
    $engine = app(ContentOpportunityEngine::class);

    $first = $engine->run($workspace, (string) $site->id, ['source_type' => 'test']);
    $count = ContentOpportunity::query()->where('workspace_id', $workspace->id)->count();
    $second = $engine->run($workspace, (string) $site->id, ['source_type' => 'test']);

    expect(ContentOpportunity::query()->where('workspace_id', $workspace->id)->count())->toBe($count)
        ->and($first->created_count)->toBeGreaterThan(0)
        ->and($second->refreshed_count)->toBeGreaterThan(0);
});

it('creates single and chained briefs from a content opportunity', function () {
    [$organization, $workspace, $site] = makeContentOpportunityEngineScope();
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);
    app(ContentOpportunityEngine::class)->run($workspace, (string) $site->id, ['source_type' => 'test']);
    $opportunity = ContentOpportunity::query()->where('type', 'comparison_page')->firstOrFail();

    $this->withoutMiddleware([
        EnsureEmailCodeVerified::class,
        EnsureUserApproved::class,
        EnsureUserHasOrganization::class,
        EnsureBillingOnboardingCompleted::class,
    ]);

    $singleResponse = $this->actingAs($user)
        ->post(route('app.agentic-marketing.content-opportunities.brief.create', $opportunity), [
            'mode' => 'single',
        ]);

    $brief = Brief::query()->latest()->firstOrFail();
    $singleResponse
        ->assertRedirect(route('app.content.workspace.show', $brief))
        ->assertSessionHas('status', 'Brief created from opportunity. Generate a single article draft when ready.');

    expect($brief->source)->toBe('content_opportunity')
        ->and(data_get($brief->client_refs, 'content_opportunity.id'))->toBe((string) $opportunity->id)
        ->and(data_get($brief->client_refs, 'source_briefing.chain_proposal'))->toBeNull()
        ->and($opportunity->fresh()->status)->toBe(ContentOpportunity::STATUS_PLANNED);

    $chainedResponse = $this->actingAs($user)
        ->post(route('app.agentic-marketing.content-opportunities.brief.create', $opportunity), [
            'mode' => 'chained',
        ]);

    $chainedBrief = Brief::query()
        ->get()
        ->first(fn (Brief $brief): bool => data_get($brief->client_refs, 'source_briefing.chain_proposal') !== null);
    $chainedResponse
        ->assertRedirect(route('app.content.series.create', ['source_brief' => $chainedBrief->id]))
        ->assertSessionHas('status', 'Brief created from opportunity. Review the chained article plan.');

    expect(data_get($chainedBrief->client_refs, 'source_briefing.chain_proposal.pillar_topic'))->not->toBeEmpty()
        ->and(data_get($chainedBrief->client_refs, 'source_briefing.chain_proposal.supporting_subtopics'))->not->toBeEmpty();
});

it('keeps the content opportunity brief route on the legacy writer when the canonical writer flag is disabled', function () {
    [$organization, $workspace, $site] = makeContentOpportunityEngineScope();
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);
    $opportunity = makeRouteContentOpportunity($organization, $workspace, $site);
    Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'content_opportunity_id' => $opportunity->id,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical route title',
        'topic' => 'canonical route topic',
        'evidence' => [['type' => 'canonical_signal']],
    ]);

    expect(config('features.mos_canonical_content_opportunity_brief_writer'))->toBeFalse();

    $this->withoutMiddleware([
        EnsureEmailCodeVerified::class,
        EnsureUserApproved::class,
        EnsureUserHasOrganization::class,
        EnsureBillingOnboardingCompleted::class,
    ]);

    $response = $this->actingAs($user)
        ->post(route('app.agentic-marketing.content-opportunities.brief.create', $opportunity), [
            'mode' => 'single',
        ]);

    $brief = Brief::query()->firstOrFail();

    $response
        ->assertRedirect(route('app.content.workspace.show', $brief))
        ->assertSessionHas('status', 'Brief created from opportunity. Generate a single article draft when ready.');

    expect($brief->title)->toBe('Route migration opportunity')
        ->and(data_get($brief->client_refs, 'content_opportunity.id'))->toBe((string) $opportunity->id)
        ->and(data_get($brief->client_refs, 'canonical_opportunity_id'))->toBeNull()
        ->and($opportunity->fresh()->status)->toBe(ContentOpportunity::STATUS_PLANNED);
});

it('uses the canonical brief writer for eligible linked records when the feature flag is enabled', function () {
    config(['features.mos_canonical_content_opportunity_brief_writer' => true]);
    [$organization, $workspace, $site] = makeContentOpportunityEngineScope();
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);
    $opportunity = makeRouteContentOpportunity($organization, $workspace, $site);
    $canonical = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'content_opportunity_id' => $opportunity->id,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical route title',
        'topic' => 'canonical route topic',
        'recommended_actions' => [['title' => 'Create the canonical route brief']],
        'evidence' => [['type' => 'canonical_signal']],
    ]);

    $this->withoutMiddleware([
        EnsureEmailCodeVerified::class,
        EnsureUserApproved::class,
        EnsureUserHasOrganization::class,
        EnsureBillingOnboardingCompleted::class,
    ]);

    $response = $this->actingAs($user)
        ->post(route('app.agentic-marketing.content-opportunities.brief.create', $opportunity), [
            'mode' => 'single',
        ]);

    $brief = Brief::query()->firstOrFail();

    $response
        ->assertRedirect(route('app.content.workspace.show', $brief))
        ->assertSessionHas('status', 'Brief created from opportunity. Generate a single article draft when ready.');

    expect($brief->title)->toBe('Canonical route title')
        ->and($brief->primary_keyword)->toBe('canonical route topic')
        ->and(data_get($brief->client_refs, 'content_opportunity.id'))->toBe((string) $opportunity->id)
        ->and(data_get($brief->client_refs, 'content_opportunity_id'))->toBe((string) $opportunity->id)
        ->and(data_get($brief->client_refs, 'canonical_opportunity_id'))->toBe((string) $canonical->id)
        ->and(data_get($brief->client_refs, 'source_signature'))->not->toBeEmpty()
        ->and($opportunity->fresh()->status)->toBe(ContentOpportunity::STATUS_PLANNED)
        ->and($canonical->fresh()->status)->toBe(OpportunityStatus::OPEN);
});

it('falls back to the legacy brief writer for unsafe canonical route candidates', function () {
    config(['features.mos_canonical_content_opportunity_brief_writer' => true]);
    [$organization, $workspace, $site] = makeContentOpportunityEngineScope();
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);
    $opportunity = makeRouteContentOpportunity($organization, $workspace, $site, [
        'reasoning' => null,
        'why_this_matters' => null,
        'why_now' => null,
        'source_signals' => [],
    ]);
    $canonical = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'content_opportunity_id' => $opportunity->id,
        'status' => OpportunityStatus::OPEN,
        'title' => '',
        'topic' => '',
        'evidence' => [],
    ]);

    $this->withoutMiddleware([
        EnsureEmailCodeVerified::class,
        EnsureUserApproved::class,
        EnsureUserHasOrganization::class,
        EnsureBillingOnboardingCompleted::class,
    ]);

    $response = $this->actingAs($user)
        ->post(route('app.agentic-marketing.content-opportunities.brief.create', $opportunity), [
            'mode' => 'chained',
        ]);

    $brief = Brief::query()->firstOrFail();

    $response
        ->assertRedirect(route('app.content.series.create', ['source_brief' => $brief->id]))
        ->assertSessionHas('status', 'Brief created from opportunity. Review the chained article plan.');

    expect($brief->title)->toBe('Route migration opportunity')
        ->and(data_get($brief->client_refs, 'canonical_opportunity_id'))->toBeNull()
        ->and(data_get($brief->client_refs, 'source_briefing.chain_proposal'))->not->toBeNull()
        ->and($opportunity->fresh()->status)->toBe(ContentOpportunity::STATUS_PLANNED)
        ->and($canonical->fresh()->status)->toBe(OpportunityStatus::OPEN);
});

it('reuses an existing canonical-created brief instead of duplicating route submissions', function () {
    config(['features.mos_canonical_content_opportunity_brief_writer' => true]);
    [$organization, $workspace, $site] = makeContentOpportunityEngineScope();
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);
    $opportunity = makeRouteContentOpportunity($organization, $workspace, $site);
    $canonical = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'content_opportunity_id' => $opportunity->id,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical route title',
        'topic' => 'canonical route topic',
        'evidence' => [['type' => 'canonical_signal']],
    ]);

    $this->withoutMiddleware([
        EnsureEmailCodeVerified::class,
        EnsureUserApproved::class,
        EnsureUserHasOrganization::class,
        EnsureBillingOnboardingCompleted::class,
    ]);

    $firstResponse = $this->actingAs($user)
        ->post(route('app.agentic-marketing.content-opportunities.brief.create', $opportunity), [
            'mode' => 'single',
        ]);
    $brief = Brief::query()->firstOrFail();

    $secondResponse = $this->actingAs($user)
        ->post(route('app.agentic-marketing.content-opportunities.brief.create', $opportunity->fresh()), [
            'mode' => 'single',
        ]);

    $firstResponse->assertRedirect(route('app.content.workspace.show', $brief));
    $secondResponse
        ->assertRedirect(route('app.content.workspace.show', $brief))
        ->assertSessionHas('status', 'Brief created from opportunity. Generate a single article draft when ready.');

    expect(Brief::query()->count())->toBe(1)
        ->and(data_get($brief->fresh()->client_refs, 'canonical_opportunity_id'))->toBe((string) $canonical->id)
        ->and($canonical->fresh()->status)->toBe(OpportunityStatus::OPEN);
});

it('requires an explicit site when creating a brief from a workspace opportunity with multiple sites', function () {
    [$organization, $workspace, $site] = makeContentOpportunityEngineScope();
    $secondSite = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Second Opportunity Site',
        'site_url' => 'https://second-opportunity.example.com',
        'base_url' => 'https://second-opportunity.example.com',
        'allowed_domains' => ['second-opportunity.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);
    $opportunity = ContentOpportunity::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'type' => 'article_idea',
        'status' => ContentOpportunity::STATUS_OPEN,
        'title' => 'Workspace-level opportunity',
        'reasoning' => 'Create a site-specific article.',
        'why_this_matters' => 'It supports growth.',
        'why_now' => 'The topic is timely.',
        'competitor_pressure' => 'Competitors are covering it.',
        'ai_visibility_opportunity' => 'It can answer a core question.',
        'target_audience' => 'marketers',
        'funnel_stage' => 'awareness',
        'primary_search_intent' => 'informational',
        'angle' => 'Explain the topic clearly.',
        'expected_impact' => 'medium',
        'confidence_score' => 75,
        'urgency_score' => 40,
        'business_value_score' => 50,
        'priority_score' => 60,
        'localization_recommendation' => ['priority_locales' => ['en']],
        'suggested_cta' => 'Read more',
        'suggested_schema' => 'Article',
        'dedupe_hash' => hash('sha256', 'workspace-level-opportunity'),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $this->withoutMiddleware([
        EnsureEmailCodeVerified::class,
        EnsureUserApproved::class,
        EnsureUserHasOrganization::class,
        EnsureBillingOnboardingCompleted::class,
    ]);

    $this->actingAs($user)
        ->post(route('app.agentic-marketing.content-opportunities.brief.create', $opportunity), [
            'mode' => 'single',
        ])
        ->assertSessionHasErrors('site_id');

    $response = $this->actingAs($user)
        ->post(route('app.agentic-marketing.content-opportunities.brief.create', $opportunity), [
            'mode' => 'single',
            'site_id' => (string) $secondSite->id,
        ]);

    $brief = Brief::query()->latest()->firstOrFail();

    $response->assertRedirect(route('app.content.workspace.show', $brief));
    expect((string) $brief->client_site_id)->toBe((string) $secondSite->id)
        ->and((string) $brief->client_site_id)->not->toBe((string) $site->id);
});
