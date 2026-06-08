<?php

use App\Models\CampaignCluster;
use App\Models\CampaignClusterRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\CompanyIntelligenceProfile;
use App\Models\CompetitorContentOpportunity;
use App\Models\Content;
use App\Models\ContentOpportunity;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingActionExecutor;
use App\Services\CampaignClusterEngine\CampaignClusterPlanningEngine;
use App\Services\CompanyIntelligence\CompanyIntelligenceNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);
});

function makeCampaignClusterScope(): array
{
    config(['features.agentic_marketing' => true]);

    $organization = Organization::query()->create([
        'name' => 'Campaign Cluster Org',
        'slug' => 'campaign-cluster-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);
    $workspace = Workspace::query()->create([
        'name' => 'Campaign Cluster Workspace',
        'organization_id' => $organization->id,
        'enabled_content_languages' => ['en', 'nl'],
    ]);
    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Campaign Site',
        'site_url' => 'https://clusters.example.com',
        'base_url' => 'https://clusters.example.com',
        'allowed_domains' => ['clusters.example.com'],
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
        'uvp' => 'Turns intelligence into campaign ecosystems.',
        'primary_topics' => ['AI visibility'],
        'authority_areas' => ['answer engine optimization'],
        'target_entities' => ['AI visibility', 'AEO'],
        'locales' => ['en', 'nl'],
        'status' => CompanyIntelligenceProfile::STATUS_ACTIVE,
        'is_default' => true,
    ]));

    foreach ([
        ['type' => 'comparison_page', 'title' => 'Best AI visibility platforms', 'intent' => 'comparison', 'stage' => 'decision'],
        ['type' => 'implementation_guide', 'title' => 'How to implement AI visibility', 'intent' => 'implementation', 'stage' => 'consideration'],
        ['type' => 'answer_block_opportunity', 'title' => 'AI visibility answer blocks', 'intent' => 'informational', 'stage' => 'awareness'],
    ] as $index => $payload) {
        ContentOpportunity::query()->create([
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'type' => $payload['type'],
            'status' => ContentOpportunity::STATUS_OPEN,
            'freshness_status' => 'fresh',
            'title' => $payload['title'],
            'reasoning' => 'AI visibility cluster opportunity.',
            'why_this_matters' => 'Builds authority.',
            'why_now' => 'Demand is active.',
            'competitor_pressure' => 'Competitors are visible.',
            'ai_visibility_opportunity' => 'Good answer extraction fit.',
            'target_audience' => 'marketers',
            'funnel_stage' => $payload['stage'],
            'primary_search_intent' => $payload['intent'],
            'angle' => 'Practical operator guidance.',
            'expected_impact' => 'strategic',
            'confidence_score' => 82,
            'urgency_score' => 78,
            'business_value_score' => 86,
            'priority_score' => 88 - $index,
            'related_entities' => ['AI visibility'],
            'recommended_internal_links' => [],
            'suggested_cta' => 'Book a demo',
            'suggested_schema' => 'Article',
            'dedupe_hash' => hash('sha256', 'cluster-opportunity-'.$index),
            'normalized_payload' => ['candidate' => ['topic' => 'AI visibility']],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    CompetitorContentOpportunity::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => 'comparison_page',
        'status' => 'open',
        'title' => 'AI visibility comparison page gap',
        'topic' => 'AI visibility',
        'query_intent' => 'comparison',
        'funnel_stage' => 'decision',
        'recommended_format' => 'comparison_page',
        'priority_score' => 90,
        'confidence_score' => 80,
        'impact_score' => 88,
        'effort_score' => 45,
        'attackable_angle' => 'Compare operational depth.',
        'reason' => 'Competitors own comparison demand.',
        'dedupe_hash' => hash('sha256', 'cluster-competitor-gap'),
        'last_seen_at' => now(),
    ]);

    return [$organization, $user, $workspace, $site];
}

it('generates campaign clusters with maps scores and dependencies', function () {
    [, , $workspace, $site] = makeCampaignClusterScope();

    $run = app(CampaignClusterPlanningEngine::class)->run($workspace, (string) $site->id, ['source_type' => 'test']);

    $cluster = CampaignCluster::query()->where('workspace_id', $workspace->id)->firstOrFail();
    expect($run)->toBeInstanceOf(CampaignClusterRun::class)
        ->and($run->status)->toBe('completed')
        ->and($cluster->primary_topic)->toBe('ai visibility')
        ->and($cluster->items()->count())->toBeGreaterThanOrEqual(6)
        ->and($cluster->dependencies()->count())->toBeGreaterThan(0)
        ->and($cluster->authority_score)->toBeGreaterThan(0)
        ->and($cluster->topical_coverage_score)->toBeGreaterThan(0)
        ->and($cluster->visual_map['topic_relationships']['nodes'])->toBeArray()
        ->and($cluster->publishing_sequence)->toBeArray()
        ->and($cluster->localization_strategy['priority_locales'])->toContain('nl');
});

it('generates fallback clusters from existing content when intelligence inputs are empty', function () {
    [, , $workspace, $site] = makeCampaignClusterScope();
    CompanyIntelligenceProfile::query()->where('workspace_id', $workspace->id)->delete();
    ContentOpportunity::query()->where('workspace_id', $workspace->id)->delete();
    CompetitorContentOpportunity::query()->where('workspace_id', $workspace->id)->delete();

    Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'WordPress AI visibility playbook',
        'primary_keyword' => 'WordPress AI visibility',
        'type' => 'article',
        'status' => 'published',
        'source' => 'api',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
        'language' => 'en',
        'first_published_at' => now(),
        'lifecycle_stage' => 'published',
    ]);

    $run = app(CampaignClusterPlanningEngine::class)->run($workspace, (string) $site->id, ['source_type' => 'test']);
    $cluster = CampaignCluster::query()->where('workspace_id', $workspace->id)->firstOrFail();

    expect($run->status)->toBe('completed')
        ->and($run->created_count)->toBeGreaterThan(0)
        ->and($cluster->primary_topic)->toBe('wordpress ai visibility')
        ->and($cluster->items()->count())->toBeGreaterThanOrEqual(6)
        ->and($cluster->dependencies()->count())->toBeGreaterThan(0);
});

it('renders campaign cluster overview and detail UI', function () {
    [, $user, $workspace, $site] = makeCampaignClusterScope();
    app(CampaignClusterPlanningEngine::class)->run($workspace, (string) $site->id, ['source_type' => 'test']);
    $cluster = CampaignCluster::query()->firstOrFail();

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.campaign-clusters.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Campaign Cluster Planning')
        ->assertSee($cluster->name);

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.campaign-clusters.show', $cluster))
        ->assertOk()
        ->assertSee('Topic Relationship Map')
        ->assertSee('Internal Link Architecture');
});

it('materializes campaign cluster publishing sequence into generation actions', function () {
    [, $user, $workspace, $site] = makeCampaignClusterScope();
    app(CampaignClusterPlanningEngine::class)->run($workspace, (string) $site->id, ['source_type' => 'test']);
    $cluster = CampaignCluster::query()->with('items')->firstOrFail();

    $this->actingAs($user)
        ->post(route('app.agentic-marketing.campaign-clusters.actions.materialize', $cluster))
        ->assertRedirect(route('app.agentic-marketing.campaign-clusters.show', $cluster))
        ->assertSessionHas('status');

    $objective = AgenticMarketingObjective::query()
        ->where('workspace_id', $workspace->id)
        ->where('name', 'Campaign cluster: '.$cluster->name)
        ->firstOrFail();

    expect(AgenticMarketingOpportunity::query()->where('objective_id', $objective->id)->count())
        ->toBe($cluster->items()->count())
        ->and(AgenticMarketingAction::query()->where('objective_id', $objective->id)->where('action_type', 'create_article')->count())
        ->toBe($cluster->items()->where('type', '!=', 'answer_blocks')->count())
        ->and(AgenticMarketingAction::query()->where('objective_id', $objective->id)->where('action_type', 'add_answer_block')->count())
        ->toBe($cluster->items()->where('type', 'answer_blocks')->count())
        ->and(AgenticMarketingAction::query()
            ->where('objective_id', $objective->id)
            ->where('action_type', 'create_article')
            ->where('title', 'like', '%Answer Blocks%')
            ->exists())
        ->toBeFalse();

    $firstAction = AgenticMarketingAction::query()->where('objective_id', $objective->id)->firstOrFail();
    expect(data_get($firstAction->payload, 'planning.prerequisites.met'))->toBeTrue()
        ->and(data_get($firstAction->payload, 'planning.risk_level'))->toBe('medium')
        ->and(data_get($firstAction->payload, 'proposal_details.topic'))->not->toBeEmpty()
        ->and(data_get($firstAction->payload, 'client_site_id'))->toBe($site->id)
        ->and(data_get($firstAction->payload, 'primary_keyword'))->not->toBeEmpty()
        ->and(data_get($firstAction->payload, 'target_audience'))->not->toBeEmpty();

    $firstAction->forceFill([
        'status' => AgenticMarketingAction::STATUS_APPROVED,
        'estimated_credits' => 0,
    ])->save();
    app(AgenticMarketingActionExecutor::class)->execute($firstAction, $user);

    $firstAction->refresh();
    $content = Content::query()->findOrFail(data_get($firstAction->result, 'created_content_id'));
    $brief = Brief::query()->where('content_id', $content->id)->firstOrFail();
    $draft = Draft::query()->findOrFail(data_get($firstAction->result, 'created_draft_id'));

    expect($content->client_site_id)->toBe($site->id)
        ->and($brief->target_audience)->not->toBeEmpty()
        ->and($brief->primary_keyword)->not->toBeEmpty()
        ->and($brief->search_intent)->not->toBeEmpty()
        ->and($draft->brief_id)->toBe($brief->id)
        ->and($draft->content_html)->toContain('Why it matters for AI visibility')
        ->and($draft->content_html)->not->toContain('Draft outline');

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.campaign-clusters.show', $cluster))
        ->assertOk()
        ->assertSee('Create generation actions')
        ->assertSee('Action ready');
});
