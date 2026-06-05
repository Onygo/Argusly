<?php

use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\LlmTracking\AiAttentionDashboardBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function llmDashboardTestSite(): ClientSite
{
    $organization = Organization::query()->create([
        'name' => 'Dash Org',
        'slug' => 'dash-' . Str::random(6),
        'status' => 'approved',
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'name' => 'Dash Workspace',
    ]);

    return ClientSite::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Dash Site',
        'site_url' => 'https://publishlayer.com',
        'base_url' => 'https://publishlayer.com',
        'allowed_domains' => ['publishlayer.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);
}

it('returns provider-aware dashboard components for brand visibility competitor pressure and authority gap', function () {
    $site = llmDashboardTestSite();
    $query = LlmTrackingQuery::query()->create([
        'workspace_id' => $site->workspace_id,
        'client_site_id' => $site->id,
        'name' => 'GEO tools',
        'query_text' => 'generative engine optimization tools',
        'target_brand' => 'PublishLayer',
        'target_domain' => 'publishlayer.com',
        'brand_terms' => ['PublishLayer'],
        'competitor_terms' => ['Semrush'],
        'target_urls' => ['https://publishlayer.com'],
        'locale' => 'en',
        'frequency' => 'daily',
        'is_active' => true,
    ]);

    LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $query->id,
        'run_at' => now(),
        'provider' => 'openai',
        'model' => 'gpt-test',
        'status' => 'succeeded',
        'brand_mentioned' => true,
        'urls_cited' => true,
        'ai_visibility_score' => 0.62,
        'owned_visibility_score' => 0.72,
        'earned_visibility_score' => 0.44,
        'competitor_pressure_score' => 0.55,
        'citation_diversity_score' => 0.38,
        'real_world_gap_score' => 0.65,
        'position_score' => 0.75,
        'citation_score' => 0.65,
        'competitor_hits' => [['term' => 'Semrush', 'count' => 2]],
        'sources' => [['url' => 'https://publishlayer.com/geo', 'domain' => 'publishlayer.com', 'type' => 'website']],
    ]);

    $query->setRelation('runs', $query->runs()->where('status', 'succeeded')->latest('run_at')->get());

    $summary = app(AiAttentionDashboardBuilder::class)->buildIndexSummary(collect([$query]));

    expect($summary)->toHaveKeys([
        'owned_visibility_score',
        'earned_visibility_score',
        'competitor_pressure_score',
        'citation_diversity_score',
        'real_world_gap_score',
        'provider_breakdown',
    ])
        ->and(data_get($summary, 'provider_breakdown.0.provider'))->toBe('openai')
        ->and(data_get($summary, 'top_competitors.0.term'))->toBe('Semrush');
});
