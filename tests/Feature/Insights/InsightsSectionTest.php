<?php

use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\LlmTrackingQuery;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('shows the top-level insights navigation item for authorized users', function () {
    [$user] = createInsightsSectionContext();

    $this->actingAs($user)
        ->get(route('app.insights.index'))
        ->assertOk()
        ->assertSee(route('app.insights.index'), false)
        ->assertSee(__('app.nav.insights'));
});

it('loads the insights hub and site overview on the new route structure', function () {
    [$user, , $site] = createInsightsSectionContext();

    expect(route('app.sites.insights.index', $site))
        ->toContain('/sites/' . $site->getRouteKey() . '/insights');

    $this->actingAs($user)
        ->get(route('app.insights.index'))
        ->assertOk()
        ->assertSee('Insights')
        ->assertSee($site->name)
        ->assertSee('Open insights');

    $this->actingAs($user)
        ->get(route('app.sites.insights.index', $site))
        ->assertOk()
        ->assertSee('Insights Overview')
        ->assertSee('LLM Visibility')
        ->assertSee('Audits')
        ->assertSee('Competitors')
        ->assertSee('Analytics')
        ->assertSee('Learnings');
});

it('loads each site-scoped insights subpage on the new urls', function () {
    [$user, , $site] = createInsightsSectionContext(withAnalytics: true);

    $this->actingAs($user)
        ->get(route('app.sites.llm-tracking.index', $site))
        ->assertOk()
        ->assertSee('LLM Visibility');

    $this->actingAs($user)
        ->get(route('app.sites.seo-audits.index', $site))
        ->assertOk()
        ->assertSee('Audits');

    $this->actingAs($user)
        ->get(route('app.sites.competitors.index', $site))
        ->assertOk()
        ->assertSee('Competitors');

    $this->actingAs($user)
        ->get(route('app.sites.analytics.show', $site))
        ->assertOk()
        ->assertSee('Analytics');

    $this->actingAs($user)
        ->get(route('app.sites.learnings.index', $site))
        ->assertOk()
        ->assertSee('Learnings');
});

it('redirects legacy insights urls to the new site-scoped insights routes', function () {
    [$user, , $site] = createInsightsSectionContext(withAnalytics: true);

    $query = LlmTrackingQuery::query()->create([
        'workspace_id' => $site->workspace_id,
        'client_site_id' => $site->id,
        'name' => 'Brand visibility',
        'query_text' => 'What is PublishLayer?',
        'brand_terms' => ['PublishLayer'],
        'competitor_terms' => [],
        'target_urls' => ['https://insights.example.com'],
        'locale' => 'en',
        'is_active' => true,
    ]);

    $legacyAnalyticsUrl = str_replace(
        '/insights/analytics',
        '/analytics',
        route('app.sites.analytics.show', ['site' => $site, 'scope' => 'all'])
    );

    $legacyLlmUrl = str_replace(
        '/insights/llm/',
        '/llm-tracking/',
        route('app.sites.llm-tracking.show', ['site' => $site, 'query' => $query, 'period' => 'week'])
    );

    $this->actingAs($user)
        ->get($legacyAnalyticsUrl)
        ->assertRedirect(route('app.sites.analytics.show', ['site' => $site, 'scope' => 'all']));

    $this->actingAs($user)
        ->get($legacyLlmUrl)
        ->assertRedirect(route('app.sites.llm-tracking.show', ['site' => $site, 'query' => $query, 'period' => 'week']));
});

it('keeps site insights scoped to the owning organization', function () {
    [$user, , $site] = createInsightsSectionContext();
    [$otherUser] = createInsightsSectionContext(organizationSlugPrefix: 'other-insights-org');

    $this->actingAs($user)
        ->get(route('app.sites.insights.index', $site))
        ->assertOk();

    $this->actingAs($otherUser)
        ->get(route('app.sites.insights.index', $site))
        ->assertNotFound();
});

it('keeps site-scoped route model binding and ownership checks in place', function () {
    [$user, $workspace, $site] = createInsightsSectionContext();

    $otherSite = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Other Site',
        'site_url' => 'https://other-insights.example.com',
        'base_url' => 'https://other-insights.example.com',
        'allowed_domains' => ['other-insights.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $query = LlmTrackingQuery::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $otherSite->id,
        'name' => 'Other query',
        'query_text' => 'Who cites the other site?',
        'brand_terms' => ['Other Site'],
        'competitor_terms' => [],
        'target_urls' => ['https://other-insights.example.com'],
        'locale' => 'en',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('app.sites.llm-tracking.show', [$site, $query]))
        ->assertNotFound();
});

function createInsightsSectionContext(bool $withAnalytics = false, string $organizationSlugPrefix = 'insights-org'): array
{
    $organization = Organization::query()->create([
        'name' => 'Insights Org',
        'slug' => $organizationSlugPrefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Insights Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Insights Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'insights-section-plan'],
        [
            'name' => 'Insights Section Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Insights Site',
        'site_url' => 'https://insights.example.com',
        'base_url' => 'https://insights.example.com',
        'allowed_domains' => ['insights.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

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

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    if ($withAnalytics) {
        AnalyticsSite::query()->create([
            'client_site_id' => $site->id,
            'allowed_domains' => ['insights.example.com'],
            'verified_at' => now(),
            'retention_days' => 365,
            'is_enabled' => true,
            'respect_dnt' => false,
            'sampling_rate' => 100,
            'flags' => [],
        ]);
    }

    return [$user, $workspace, $site];
}
