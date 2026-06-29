<?php

use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Http\Middleware\EnsureEmailCodeVerified;
use App\Http\Middleware\EnsureUserApproved;
use App\Http\Middleware\EnsureUserHasOrganization;
use App\Models\AgenticMarketingAgentMemory;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOrchestrationRun;
use App\Models\ClientSite;
use App\Models\CompanyIntelligenceProfile;
use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\Orchestration\AgentConflictResolver;
use App\Services\AgenticMarketing\Orchestration\AgentOrchestrationService;
use App\Services\AgenticMarketing\Orchestration\AgentRegistry;
use App\Services\AgenticMarketing\Orchestration\SharedMarketingContextBuilder;
use App\Services\CompanyIntelligence\CompanyIntelligenceNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([
        EnsureEmailCodeVerified::class,
        EnsureUserApproved::class,
        EnsureUserHasOrganization::class,
        EnsureBillingOnboardingCompleted::class,
    ]);
});

function makeAgentOrchestrationScope(): array
{
    $organization = Organization::query()->create([
        'name' => 'Agent Orchestration Org',
        'slug' => 'agent-orchestration-'.Str::random(6),
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
        'name' => 'Agent Orchestration Workspace',
        'organization_id' => $organization->id,
        'enabled_content_languages' => ['en', 'nl'],
    ]);
    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Agent Site',
        'site_url' => 'https://agents.example.com',
        'base_url' => 'https://agents.example.com',
        'allowed_domains' => ['agents.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);
    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'AI visibility growth',
        'goal' => 'Coordinate specialized agents for AI visibility planning.',
        'locale' => 'en',
        'approval_mode' => 'manual',
        'status' => 'active',
    ]);

    CompanyIntelligenceProfile::query()->create(app(CompanyIntelligenceNormalizer::class)->persistencePayload([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'brand_key' => 'primary',
        'company_name' => 'Argusly',
        'company_description' => 'Agentic marketing platform.',
        'market_category' => 'Agentic marketing',
        'positioning' => 'AI content operations for visibility.',
        'uvp' => 'Turns intelligence into orchestrated marketing workflows.',
        'primary_topics' => ['AI visibility'],
        'authority_areas' => ['answer engine optimization'],
        'target_entities' => ['AI visibility', 'AEO'],
        'buyer_roles' => ['marketers', 'founders'],
        'locales' => ['en', 'nl'],
        'status' => CompanyIntelligenceProfile::STATUS_ACTIVE,
        'is_default' => true,
    ]));

    ContentOpportunity::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => 'implementation_guide',
        'status' => ContentOpportunity::STATUS_OPEN,
        'freshness_status' => 'fresh',
        'title' => 'AI visibility implementation guide',
        'reasoning' => 'High priority implementation demand.',
        'why_this_matters' => 'Builds authority.',
        'why_now' => 'Competitors are moving.',
        'competitor_pressure' => 'Active pressure.',
        'ai_visibility_opportunity' => 'Answer extraction fit.',
        'target_audience' => 'marketers',
        'funnel_stage' => 'consideration',
        'primary_search_intent' => 'implementation',
        'angle' => 'Operator workflow.',
        'expected_impact' => 'strategic',
        'confidence_score' => 85,
        'urgency_score' => 80,
        'business_value_score' => 88,
        'priority_score' => 90,
        'related_entities' => ['AI visibility'],
        'recommended_internal_links' => [],
        'suggested_cta' => 'Book a demo',
        'suggested_schema' => 'HowTo',
        'dedupe_hash' => hash('sha256', 'agent-orchestration-opportunity'),
        'normalized_payload' => ['candidate' => ['topic' => 'AI visibility']],
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    return [$organization, $user, $workspace, $site, $objective];
}

it('registers the initial specialized marketing agents', function () {
    $definitions = app(AgentRegistry::class)->definitions();

    expect($definitions)->toHaveCount(8)
        ->and(collect($definitions)->pluck('key')->all())->toContain('seo_strategist')
        ->and(collect($definitions)->pluck('key')->all())->toContain('aeo_answer_engine');
});

it('runs an inline orchestration workflow with shared context memory traces and normalized results', function () {
    [, $user, $workspace, $site, $objective] = makeAgentOrchestrationScope();

    $run = app(AgentOrchestrationService::class)->start(
        workspace: $workspace,
        clientSiteId: (string) $site->id,
        objective: $objective,
        actor: $user,
        input: ['focus_topic' => 'AI visibility', 'provider_key' => 'deterministic'],
        runInline: true,
    );

    expect($run)->toBeInstanceOf(AgenticMarketingOrchestrationRun::class)
        ->and($run->status)->toBe('completed')
        ->and($run->tasks_count)->toBe(8)
        ->and($run->completed_tasks_count)->toBe(8)
        ->and($run->confidence_score)->toBeGreaterThan(0)
        ->and($run->normalized_result['recommendations'])->toBeArray()
        ->and($run->traces()->count())->toBeGreaterThan(0)
        ->and(AgenticMarketingAgentMemory::query()->where('workspace_id', $workspace->id)->count())->toBeGreaterThan(0);
});

it('adds canonical opportunity ids to shared agentic context while preserving legacy fallback', function () {
    [, , $workspace, $site, $objective] = makeAgentOrchestrationScope();
    $legacy = ContentOpportunity::query()->where('workspace_id', $workspace->id)->firstOrFail();
    $canonical = Opportunity::factory()->create([
        'organization_id' => $legacy->organization_id,
        'workspace_id' => $legacy->workspace_id,
        'client_site_id' => $legacy->client_site_id,
        'content_opportunity_id' => $legacy->id,
        'title' => 'Canonical agentic opportunity',
        'priority_score' => 97,
        'confidence_score' => 92,
        'impact_score' => 90,
    ]);

    $context = app(SharedMarketingContextBuilder::class)->build($workspace, (string) $site->id, $objective);
    $opportunity = $context['opportunities'][0];

    expect($opportunity)->toMatchArray([
        'id' => (string) $legacy->id,
        'canonical_opportunity_id' => (string) $canonical->id,
        'title' => 'Canonical agentic opportunity',
        'priority_score' => 97.0,
    ])
        ->and($opportunity['provenance'])->toMatchArray([
            'title' => 'canonical',
            'type' => 'legacy',
            'status' => 'legacy',
        ]);

    $canonical->delete();
    $fallback = app(SharedMarketingContextBuilder::class)->build($workspace, (string) $site->id, $objective);

    expect($fallback['opportunities'][0]['canonical_opportunity_id'])->toBeNull()
        ->and($fallback['opportunities'][0]['title'])->toBe('AI visibility implementation guide');
});

it('renders the debugging UI for orchestration runs', function () {
    [, $user, $workspace, $site, $objective] = makeAgentOrchestrationScope();
    $run = app(AgentOrchestrationService::class)->start($workspace, (string) $site->id, $objective, $user, ['focus_topic' => 'AI visibility'], true);

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.orchestration.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Agent Orchestration')
        ->assertSee('SEO strategist agent')
        ->assertSee('Focus:')
        ->assertSee('AI visibility');

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.orchestration.show', $run))
        ->assertOk()
        ->assertSee('Recommended Next Actions')
        ->assertSee('Strategic Notes')
        ->assertSee('Agent Details');
});

it('translates the orchestration index when Dutch is selected', function () {
    [, $user, $workspace, $site, $objective] = makeAgentOrchestrationScope();
    app(AgentOrchestrationService::class)->start($workspace, (string) $site->id, $objective, $user, ['focus_topic' => 'AI visibility'], true);

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.orchestration.index', ['workspace_id' => $workspace->id, 'lang' => 'nl']))
        ->assertOk()
        ->assertSee('Co&ouml;rdineer gespecialiseerde Agentic Marketing-agents', false)
        ->assertSee('Focustopic')
        ->assertSee('Agents uitvoeren')
        ->assertSee('SEO-strategieagent')
        ->assertSee('Contentstrategieagent')
        ->assertSee('Recente orchestration-runs')
        ->assertSee('8 taken &middot; 8 voltooid &middot; 0 conflicten', false)
        ->assertSee('Betrouwbaarheid')
        ->assertDontSee('SEO strategist agent');
});

it('translates the orchestration run detail when Dutch is selected', function () {
    [, $user, $workspace, $site, $objective] = makeAgentOrchestrationScope();
    $run = app(AgentOrchestrationService::class)->start($workspace, (string) $site->id, $objective, $user, ['focus_topic' => 'AI visibility'], true);

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.orchestration.show', ['run' => $run, 'lang' => 'nl']))
        ->assertOk()
        ->assertSee('Wat moet de klant hierna doen?')
        ->assertSee('Aanbevolen volgende acties')
        ->assertSee('Gebruik dit als klantgericht actieplan')
        ->assertSee('voorgestelde acties')
        ->assertSee('Volgende stap')
        ->assertSee('Strategische notities')
        ->assertSee('Klantcontext')
        ->assertSee('Gereedheid')
        ->assertSee('Debugtrace')
        ->assertDontSee('Recommended Next Actions');
});

it('resolves competing agent claims by confidence', function () {
    [, $user, $workspace, $site, $objective] = makeAgentOrchestrationScope();
    $run = app(AgentOrchestrationService::class)->start($workspace, (string) $site->id, $objective, $user, [
        'focus_topic' => 'AI visibility',
        'agent_keys' => ['seo_strategist'],
    ], true);

    $conflicts = app(AgentConflictResolver::class)->detectAndResolve($run, [
        'claims' => [
            ['claim_key' => 'publishing_sequence:ai-visibility', 'value' => 'pillar_first', 'confidence_score' => 88, 'agent_key' => 'campaign_planner'],
            ['claim_key' => 'publishing_sequence:ai-visibility', 'value' => 'comparison_first', 'confidence_score' => 72, 'agent_key' => 'competitor_analyst'],
        ],
    ]);

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['resolution']['selected_value'])->toBe('pillar_first')
        ->and($run->conflicts()->count())->toBe(1);
});
