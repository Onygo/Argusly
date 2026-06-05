<?php

use App\Models\ClientSite;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\LlmTrackingQuerySet;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates query sets and assigns new tracking queries to them', function () {
    [$user, $workspace, $site] = createLlmTrackingFilterContext();

    $this->actingAs($user)
        ->post(route('app.sites.llm-tracking.query-sets.store', $site), [
            'name' => 'Brand Monitoring',
            'description' => 'Brand and review prompts',
            'locale' => 'en',
            'is_active' => '1',
        ])
        ->assertRedirect(route('app.sites.llm-tracking.index', $site));

    $querySet = LlmTrackingQuerySet::query()->where('client_site_id', $site->id)->where('name', 'Brand Monitoring')->first();

    expect($querySet)->not->toBeNull();

    $this->actingAs($user)
        ->post(route('app.sites.llm-tracking.store', $site), [
            'llm_tracking_query_set_id' => $querySet->id,
            'name' => 'Brand reputation',
            'query_text' => 'What is PublishLayer known for?',
            'target_brand' => 'PublishLayer',
            'target_domain' => 'publishlayer.com',
            'brand_terms' => 'PublishLayer',
            'competitor_terms' => 'AcmeSEO',
            'target_urls' => 'https://publishlayer.com/features',
            'tags' => 'brand, monitoring',
            'locale' => 'en',
            'frequency' => 'daily',
            'priority' => 90,
            'is_active' => '1',
        ])
        ->assertRedirect(route('app.sites.llm-tracking.index', $site));

    $query = LlmTrackingQuery::query()->where('client_site_id', $site->id)->where('name', 'Brand reputation')->first();

    expect((int) $query?->llm_tracking_query_set_id)->toBe((int) $querySet->id)
        ->and((string) $query?->target_brand)->toBe('PublishLayer')
        ->and((string) $query?->target_domain)->toBe('publishlayer.com')
        ->and((array) $query?->tags)->toBe(['brand', 'monitoring']);
});

it('filters the dashboard by query set and provider', function () {
    [$user, $workspace, $site] = createLlmTrackingFilterContext();

    $brandSet = LlmTrackingQuerySet::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Brand Monitoring',
        'description' => 'Brand prompts',
        'locale' => 'en',
        'is_active' => true,
    ]);

    $geoSet = LlmTrackingQuerySet::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'AI / GEO Focus',
        'description' => 'Discovery prompts',
        'locale' => 'en',
        'is_active' => true,
    ]);

    $brandQuery = LlmTrackingQuery::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'llm_tracking_query_set_id' => $brandSet->id,
        'name' => 'Brand reputation',
        'query_text' => 'What is PublishLayer known for?',
        'target_brand' => 'PublishLayer',
        'target_domain' => 'publishlayer.com',
        'brand_terms' => ['PublishLayer'],
        'competitor_terms' => ['AcmeSEO'],
        'target_urls' => ['https://publishlayer.com/features'],
        'locale' => 'en',
        'frequency' => 'daily',
        'priority' => 80,
        'is_active' => true,
    ]);

    $geoQuery = LlmTrackingQuery::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'llm_tracking_query_set_id' => $geoSet->id,
        'name' => 'GEO comparison',
        'query_text' => 'Best AI SEO visibility platform',
        'target_brand' => 'PublishLayer',
        'target_domain' => 'publishlayer.com',
        'brand_terms' => ['PublishLayer'],
        'competitor_terms' => ['OtherBrand'],
        'target_urls' => ['https://publishlayer.com/features'],
        'locale' => 'en',
        'frequency' => 'weekly',
        'priority' => 60,
        'is_active' => true,
    ]);

    LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $brandQuery->id,
        'run_at' => now()->subDay(),
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'succeeded',
        'answer_text' => 'PublishLayer is visible here.',
        'normalized_response' => 'PublishLayer is visible here.',
        'brand_mentioned' => true,
        'urls_cited' => true,
        'presence_score' => 1.0,
        'position_score' => 1.0,
        'citation_score' => 1.0,
        'context_score' => 0.6,
        'context_label' => 'neutral',
        'sentiment_score' => 0.6,
        'sentiment_label' => 'neutral',
        'competitive_score' => 1.0,
        'competitor_share_score' => 1.0,
        'ai_visibility_score' => 0.92,
    ]);

    LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $geoQuery->id,
        'run_at' => now()->subDay(),
        'provider' => 'anthropic',
        'model' => 'claude-3-5-sonnet-latest',
        'status' => 'succeeded',
        'answer_text' => 'OtherBrand dominates here.',
        'normalized_response' => 'OtherBrand dominates here.',
        'brand_mentioned' => false,
        'urls_cited' => false,
        'presence_score' => 0.0,
        'position_score' => 0.0,
        'citation_score' => 0.0,
        'context_score' => 0.0,
        'context_label' => 'not_present',
        'sentiment_score' => 0.0,
        'sentiment_label' => 'not_present',
        'competitive_score' => 0.0,
        'competitor_share_score' => 0.0,
        'ai_visibility_score' => 0.0,
    ]);

    $this->actingAs($user)
        ->get(route('app.sites.llm-tracking.index', [
            'site' => $site,
            'query_set_id' => $brandSet->id,
            'provider' => 'openai',
            'period' => '30d',
        ]))
        ->assertOk()
        ->assertSee('Brand reputation')
        ->assertDontSee('GEO comparison')
        ->assertSee('Latest Answers')
        ->assertSee('Brand Monitoring');
});

function createLlmTrackingFilterContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Tracking Filter Org',
        'slug' => 'tracking-filter-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Tracking Filter Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Tracking Filter Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Tracking Filter Site',
        'site_url' => 'https://tracking-filter.example.com',
        'base_url' => 'https://tracking-filter.example.com',
        'allowed_domains' => ['tracking-filter.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'tracking-filter-plan'],
        [
            'name' => 'Tracking Filter Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    return [$user, $workspace, $site];
}
