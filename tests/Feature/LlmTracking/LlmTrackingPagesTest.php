<?php

use App\Models\ClientSite;
use App\Models\LlmTrackingAggregate;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows owner to view and create site llm tracking queries', function () {
    [$user, $workspace, $site] = createLlmTrackingUiContext();

    $this->actingAs($user)
        ->get(route('app.sites.llm-tracking.index', $site))
        ->assertOk()
        ->assertSee('Share of AI Attention');

    $this->actingAs($user)
        ->post(route('app.sites.llm-tracking.store', $site), [
            'name' => 'Brand query',
            'query_text' => 'What are the best alternatives to Argusly?',
            'brand_terms' => 'Argusly',
            'competitor_terms' => 'AcmeSEO',
            'target_urls' => 'https://argusly.com/features',
            'locale' => 'en',
            'frequency' => 'daily',
            'is_active' => '1',
        ])
        ->assertRedirect(route('app.sites.llm-tracking.index', $site));

    $this->assertDatabaseHas('llm_tracking_queries', [
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Brand query',
        'frequency' => 'daily',
        'is_active' => 1,
    ]);
});

it('renders product-facing llm tracking insights on index and query detail pages', function () {
    [$user, $workspace, $site] = createLlmTrackingUiContext();

    $query = LlmTrackingQuery::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Visibility benchmark',
        'query_text' => 'Best B2B content workflow platform',
        'brand_terms' => ['Argusly'],
        'competitor_terms' => ['AcmeSEO'],
        'target_urls' => ['https://argusly.com/features'],
        'locale' => 'en',
        'frequency' => 'weekly',
        'is_active' => true,
    ]);

    LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $query->id,
        'run_at' => now()->subHour(),
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'succeeded',
        'answer_text' => 'Argusly and AcmeSEO are compared with citations.',
        'brand_hits' => [
            ['term' => 'Argusly', 'count' => 2, 'bucket' => 'first', 'first_sentence_index' => 0, 'context_snippets' => ['Argusly and AcmeSEO are compared with citations.']],
        ],
        'competitor_hits' => [
            ['term' => 'AcmeSEO', 'count' => 1, 'bucket' => 'middle', 'first_sentence_index' => 1, 'context_snippets' => ['Argusly and AcmeSEO are compared with citations.']],
        ],
        'entity_presence' => [
            ['term' => 'Argusly', 'type' => 'brand', 'present' => true, 'count' => 2, 'position_score' => 1.0, 'snippet_context' => ['Argusly and AcmeSEO are compared with citations.']],
            ['term' => 'AcmeSEO', 'type' => 'competitor', 'present' => true, 'count' => 1, 'position_score' => 0.5, 'snippet_context' => ['Argusly and AcmeSEO are compared with citations.']],
        ],
        'url_hits' => [
            ['target_url' => 'https://argusly.com/features', 'count' => 1, 'bucket' => 'middle'],
        ],
        'citation_ranking' => [
            'brand' => ['bucket' => 'first', 'first_index' => 24, 'last_index' => 80, 'normalized_position' => 0.12],
            'url' => ['bucket' => 'middle', 'first_index' => 190, 'last_index' => 220, 'normalized_position' => 0.52],
        ],
        'sources' => [
            ['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 210],
            ['url' => 'https://example-news.com/analysis', 'domain' => 'example-news.com', 'type' => 'news', 'position' => 320],
        ],
        'share_of_voice_snapshot' => [
            'brand_total_mentions' => 2,
            'competitor_total_mentions' => 1,
            'share_brand' => 0.6667,
            'share_by_term' => [
                'brand' => [
                    ['term' => 'Argusly', 'count' => 2, 'share' => 0.6667],
                ],
                'competitors' => [
                    ['term' => 'AcmeSEO', 'count' => 1, 'share' => 0.3333],
                ],
            ],
        ],
        'presence_score' => 1,
        'position_score' => 1,
        'sentiment_score' => 0.6,
        'sentiment_label' => 'neutral',
        'competitive_score' => 0.6667,
        'ai_visibility_score' => 0.8533,
        'suggestions' => [
            [
                'title' => 'Create content about b2b content workflow',
                'rationale' => 'Competitors are mentioned before your brand in some answers.',
                'recommended_content_type' => 'comparison',
                'primary_keyword' => 'b2b content workflow',
                'secondary_keywords' => ['content operations', 'workflow automation'],
                'suggested_url_slug' => 'b2b-content-workflow-comparison',
                'content_topics' => ['b2b content workflow', 'content operations'],
                'landing_pages' => [['title' => 'B2B content workflow comparison', 'slug' => 'b2b-content-workflow-comparison']],
                'seo_geo_improvements' => ['Add direct-answer sections that mirror the tracked query wording.'],
            ],
        ],
        'brand_mentioned' => true,
        'urls_cited' => true,
        'competitors_mentioned' => true,
        'is_cached' => false,
    ]);

    LlmTrackingAggregate::query()->create([
        'site_id' => $site->id,
        'query_id' => $query->id,
        'period' => 'week',
        'period_start' => now()->startOfWeek()->toDateString(),
        'model' => 'gpt-4.1-mini',
        'locale' => 'en',
        'metrics' => [
            'avg_ai_visibility_score' => 0.82,
            'presence_rate' => 1.0,
            'average_position_score' => 0.85,
            'missing_visibility_count' => 0,
            'brand_share' => 0.61,
            'citation_counts' => ['brand' => ['first' => 3]],
            'top_sources_by_type' => ['news' => 2, 'website' => 1],
            'run_count' => 2,
        ],
    ]);

    $this->actingAs($user)
        ->get(route('app.sites.llm-tracking.index', $site))
        ->assertOk()
        ->assertSee('AI Visibility Score')
        ->assertSee('Missing Visibility Opportunities')
        ->assertSee('Top Competitors By Frequency')
        ->assertSee('Trend Over Time')
        ->assertSee('Argusly present')
        ->assertSee('Weekly');

    $this->actingAs($user)
        ->get(route('app.sites.llm-tracking.show', [$site, $query]))
        ->assertOk()
        ->assertSee('What this means')
        ->assertSee('Brand presence')
        ->assertSee('Competitor analysis')
        ->assertSee('Source breakdown')
        ->assertSee('Recommended actions')
        ->assertSee('Raw response')
        ->assertSee('B2B content workflow comparison');
});

it('shows clear empty states when llm tracking query has no runs', function () {
    [$user, $workspace, $site] = createLlmTrackingUiContext();

    $query = LlmTrackingQuery::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'No Run Query',
        'query_text' => 'Who talks about Argusly?',
        'brand_terms' => ['Argusly'],
        'competitor_terms' => [],
        'target_urls' => ['https://argusly.com'],
        'locale' => 'en',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('app.sites.llm-tracking.show', [$site, $query]))
        ->assertOk()
        ->assertSeeText('No successful run yet. Start with a fresh run to generate visibility analysis.', false)
        ->assertSeeText('No run history yet.');
});

it('seeds the default Argusly tracking queries for a argusly site', function () {
    [$user, , $site] = createLlmTrackingUiContext();

    $site->update([
        'name' => 'Argusly',
        'site_url' => 'https://argusly.com',
        'base_url' => 'https://argusly.com',
        'allowed_domains' => ['argusly.com'],
    ]);

    $this->actingAs($user)
        ->get(route('app.sites.llm-tracking.index', $site))
        ->assertOk()
        ->assertSee('SEO Visibility - Content & Platform')
        ->assertSee('AI Visibility - GEO & LLM Discovery')
        ->assertSee('Brand Presence - Argusly');

    expect(LlmTrackingQuery::query()->where('client_site_id', $site->id)->count())->toBe(3);
});

function createLlmTrackingUiContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Tracking UI Org',
        'slug' => 'tracking-ui-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Tracking UI Org BV',
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
        'name' => 'Tracking UI Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Tracking UI Site',
        'site_url' => 'https://tracking-ui.example.com',
        'allowed_domains' => ['tracking-ui.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'test-plan'],
        [
            'name' => 'Test Plan',
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
