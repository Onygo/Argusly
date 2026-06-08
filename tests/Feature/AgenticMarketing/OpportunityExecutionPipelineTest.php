<?php

use App\Enums\AgenticMarketingOpportunityStatus;
use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingExecutionAsset;
use App\Models\AgenticMarketingExecutionPipeline;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingRunItem;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\CompetitorTopicSignal;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\ExecutionPipeline\OpportunityExecutionPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeOpportunityExecutionScope(): array
{
    $organization = Organization::query()->create([
        'name' => 'Execution Pipeline Org',
        'slug' => 'execution-pipeline-' . Str::random(6),
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
        'name' => 'Execution Pipeline Workspace',
        'organization_id' => $organization->id,
    ]);
    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Execution Site',
        'site_url' => 'https://execution.example.com',
        'base_url' => 'https://execution.example.com',
        'allowed_domains' => ['execution.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);
    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Execution objective',
        'goal' => 'Prepare opportunity execution work.',
        'locale' => 'en',
        'approval_mode' => 'manual',
        'status' => 'active',
    ]);
    $opportunity = AgenticMarketingOpportunity::query()->create([
        'objective_id' => $objective->id,
        'title' => 'AI visibility implementation guide',
        'type' => AgenticMarketingOpportunityType::NewArticle->value,
        'priority_score' => 88,
        'status' => AgenticMarketingOpportunityStatus::Open->value,
        'payload' => [
            'topic' => 'AI visibility implementation',
            'reasoning' => 'This opportunity should become a practical execution asset set.',
            'angle' => 'Show the implementation workflow for marketers.',
            'target_audience' => 'marketers',
            'funnel_stage' => 'consideration',
            'primary_search_intent' => 'implementation',
            'suggested_cta' => 'Book a demo',
            'suggested_schema' => 'HowTo',
            'related_entities' => ['AI visibility', 'AEO'],
        ],
    ]);

    return [$organization, $user, $workspace, $site, $objective, $opportunity];
}

it('prepares execution assets from a single opportunity', function () {
    [, $user, , , , $opportunity] = makeOpportunityExecutionScope();

    $pipeline = app(OpportunityExecutionPipelineService::class)->prepare($opportunity, 'manual', $user);

    expect($pipeline)->toBeInstanceOf(AgenticMarketingExecutionPipeline::class)
        ->and($pipeline->status)->toBe('awaiting_approval')
        ->and($pipeline->assets_count)->toBeGreaterThanOrEqual(9)
        ->and($pipeline->publishing_readiness)->toBe('needs_review')
        ->and(data_get($pipeline->result, 'why_this_matters.summary'))->toBe('This opportunity should become a practical execution asset set.')
        ->and(data_get($pipeline->result, 'confidence_risk_scores.confidence_score'))->toBeGreaterThan(0)
        ->and(data_get($pipeline->result, 'confidence_risk_scores.requires_human_validation'))->toBeTrue()
        ->and(collect(data_get($pipeline->result, 'execution_timeline'))->pluck('event')->all())->toContain('opportunity.detected', 'brief.generated', 'review.pending', 'publish.ready', 'social_handoff.pending', 'refresh.scheduled')
        ->and(collect(data_get($pipeline->result, 'asset_inventory'))->pluck('label')->all())->toContain('Brief', 'Article draft', 'Answer blocks', 'Schema', 'CTA variants')
        ->and(data_get($pipeline->result, 'publishing_readiness.why_not_ready'))->not->toBeEmpty()
        ->and(Brief::query()->count())->toBe(1)
        ->and(Draft::query()->count())->toBe(1)
        ->and(AgenticMarketingExecutionAsset::query()->where('type', 'answer_blocks')->exists())->toBeTrue()
        ->and(AgenticMarketingExecutionAsset::query()->where('type', 'automation_schedule')->exists())->toBeTrue()
        ->and($pipeline->auditLogs()->where('event', 'pipeline.prepared')->exists())->toBeTrue();
});

it('queues execution preparation from the opportunity route', function () {
    [, $user, , , , $opportunity] = makeOpportunityExecutionScope();
    Bus::fake();

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $this->actingAs($user)
        ->post(route('app.agentic-marketing.opportunities.execution.prepare', $opportunity), [
            'mode' => 'manual',
        ])
        ->assertRedirect(route('app.agentic-marketing.opportunities.execution.show', $opportunity))
        ->assertSessionHas('status', 'Execution pipeline queued.');
});

it('translates the empty opportunity execution page when Dutch is selected', function () {
    [, $user, , , , $opportunity] = makeOpportunityExecutionScope();

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.opportunities.execution.show', $opportunity).'?lang=nl')
        ->assertOk()
        ->assertSee('Opportunity-uitvoering')
        ->assertSee('Bereid briefs, concepten, answer blocks')
        ->assertSee('Handmatig')
        ->assertSee('Review-assets voorbereiden')
        ->assertSee('Er is nog geen uitvoeringspipeline voorbereid voor deze opportunity.')
        ->assertDontSee('No execution pipeline has been prepared');
});

it('generates concrete execution output for answer faq summary cta and links', function () {
    [, $user, $workspace, , $objective, $opportunity] = makeOpportunityExecutionScope();
    $objective->forceFill([
        'goal' => 'Increase AI answer visibility and generate qualified demo intent.',
        'audience' => 'SEO specialists and content teams',
    ])->save();
    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'title' => 'AI visibility strategy for answer engines',
        'seo_title' => 'AI visibility strategy',
        'primary_keyword' => 'AI visibility strategy',
        'published_url' => 'https://execution.example.com/ai-visibility-strategy',
        'status' => 'published',
        'source' => 'manual',
        'schema_type' => 'Article',
        'freshness_score' => 42,
        'semantic_coverage_score' => 68,
        'ai_visibility_score' => 51,
    ]);
    $revision = ContentRevision::query()->create([
        'content_id' => $content->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => '<h1>AI visibility strategy</h1><p>Existing content about AI visibility. AI visibility is the ability to be found and cited in AI generated answers. According to benchmark data from 2026, teams need structured answers, entities, and source context.</p><h2>Implementation</h2><p>AI visibility improves when content explains what the topic is and links to related proof.</p><h2>FAQ</h2><p>What is AI visibility?</p><script type="application/ld+json">{"@type":"FAQPage"}</script><a href="https://execution.example.com/geo">GEO guide</a>',
        'is_active' => true,
    ]);
    $content->forceFill(['current_revision_id' => $revision->id])->save();
    $content->answerBlocks()->create([
        'question' => 'What is AI visibility?',
        'answer' => 'AI visibility is the ability for content to be found and cited in AI generated answers.',
        'entities' => ['AI visibility', 'answer engines', 'GEO'],
        'order' => 1,
    ]);
    CompetitorTopicSignal::query()->create([
        'organization_id' => $objective->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $objective->client_site_id,
        'topic' => 'ai visibility',
        'topic_hash' => hash('sha256', 'ai visibility'),
        'competitor_content_count' => 4,
        'argusly_content_count' => 1,
        'overlap_score' => 72,
        'opportunity_score' => 84,
        'coverage_status' => 'weak',
    ]);

    $opportunity->forceFill([
        'content_id' => $content->id,
        'title' => 'GEO guide for AI visibility',
        'payload' => [
            'topic' => 'GEO',
            'reasoning' => 'Teams need a practical explanation of GEO for AI generated discovery.',
            'angle' => 'Explain GEO with answer-ready structure and workflow steps.',
            'target_audience' => 'SEO specialists and content teams',
            'primary_search_intent' => 'definition',
            'suggested_cta' => 'Book a GEO strategy demo',
            'suggested_schema' => 'FAQPage',
            'related_entities' => ['Generative Engine Optimization', 'AI visibility', 'answer engines'],
        ],
    ])->save();

    $pipeline = app(OpportunityExecutionPipelineService::class)->prepare($opportunity->fresh(), 'manual', $user);

    $answerBlocks = $pipeline->assets->firstWhere('type', 'answer_blocks')->payload['blocks'] ?? [];
    $faqs = $pipeline->assets->firstWhere('type', 'faq_set')->payload['faqs'] ?? [];
    $summaries = $pipeline->assets->firstWhere('type', 'structured_summary')->payload['summaries'] ?? [];
    $ctas = $pipeline->assets->firstWhere('type', 'cta_suggestions')->payload['suggestions'] ?? [];
    $links = $pipeline->assets->firstWhere('type', 'internal_link_suggestions')->payload['links'] ?? [];
    $diff = $pipeline->assets->firstWhere('type', 'content_diff_preview')->payload ?? [];
    $graph = $pipeline->assets->firstWhere('type', 'execution_graph')->payload ?? [];
    $graphNodeIds = collect($graph['nodes'] ?? [])->pluck('id')->all();
    $clusterProposal = $pipeline->assets->firstWhere('type', 'strategic_cluster_proposal')->payload ?? [];
    $missingTypes = collect($clusterProposal['missing'] ?? [])->pluck('type')->all();
    $visibilityScorecard = $pipeline->assets->firstWhere('type', 'ai_visibility_scorecard')->payload ?? [];
    $articleScore = $visibilityScorecard['scores'][0] ?? [];
    $campaignPlan = $pipeline->assets->firstWhere('type', 'autonomous_campaign_plan')->payload ?? [];

    expect($answerBlocks[0]['question'])->toBe('What is GEO and why does it matter?')
        ->and($answerBlocks[0]['answer'])->toContain('Generative Engine Optimization (GEO)')
        ->and($answerBlocks[0]['answer'])->toContain('AI generated answers')
        ->and($faqs)->toHaveCount(5)
        ->and($summaries['key_takeaways'])->toHaveCount(3)
        ->and($ctas[0]['label'])->toBe('Book a GEO strategy demo')
        ->and($links[0]['anchor_text'])->toBe('geo strategy')
        ->and(collect($diff['before']['preview_lines'])->contains(fn (string $line): bool => str_contains($line, 'Existing content about AI visibility.')))->toBeTrue()
        ->and($diff['after']['preview_lines'])->toContain('Answer blocks')
        ->and($diff['diff']['text'])->toContain('+ Answer blocks')
        ->and(collect($diff['highlights'])->pluck('type')->all())->toContain('added_answer_block', 'inserted_faq', 'added_schema', 'inserted_internal_links')
        ->and($graphNodeIds)->toContain(
            'generate_answer_blocks',
            'add_faq_schema',
            'add_internal_links',
            'add_cta',
            'prepare_linkedin_handoff',
            'refresh_metadata',
            'queue_republish',
            'schedule_lifecycle_review'
        )
        ->and($graph['edges'])->toContain(['from' => 'refresh_metadata', 'to' => 'queue_republish'])
        ->and($pipeline->assets->firstWhere('type', 'linkedin_post')->title)->toBe('LinkedIn handoff copy')
        ->and($pipeline->assets->firstWhere('type', 'linkedin_post')->payload['publication_mode'])->toBe('external_tool_handoff')
        ->and(AgenticMarketingRunItem::query()
            ->where('run_id', $pipeline->run_id)
            ->where('payload->graph_node_id', 'generate_answer_blocks')
            ->where('status', AgenticMarketingRunItem::STATUS_COMPLETED)
            ->exists())->toBeTrue()
        ->and(AgenticMarketingRunItem::query()
            ->where('run_id', $pipeline->run_id)
            ->where('payload->graph_node_id', 'queue_republish')
            ->where('status', AgenticMarketingRunItem::STATUS_QUEUED)
            ->exists())->toBeTrue()
        ->and($clusterProposal['topic'])->toBe('AI Visibility')
        ->and($clusterProposal['estimated_impact'])->toBe('High')
        ->and($clusterProposal['priority'])->toBe(1)
        ->and($missingTypes)->toContain(
            'glossary',
            'comparison_pages',
            'faq_hub',
            'case_study',
            'implementation_guide',
            'tooling_comparison',
            'enterprise_governance_article'
        )
        ->and($visibilityScorecard['scope'])->toBe('per_article')
        ->and($articleScore['content_id'])->toBe((string) $content->id)
        ->and($articleScore)->toHaveKeys([
            'ai_discoverability_score',
            'answer_readiness_score',
            'entity_richness_score',
            'citation_likelihood_score',
            'semantic_completeness_score',
            'freshness_decay_score',
            'competitor_overlap_score',
        ])
        ->and($articleScore['freshness_decay_score'])->toBe(58)
        ->and($articleScore['competitor_overlap_score'])->toBe(84)
        ->and($articleScore['answer_readiness_score'])->toBeGreaterThan(40)
        ->and($campaignPlan['campaign'])->toBe('30 day Agentic Marketing Campaign')
        ->and($campaignPlan['operating_model'])->toBe('HubSpot + SEO platform + AI orchestration layer')
        ->and($campaignPlan['articles'])->toHaveCount(8)
        ->and($campaignPlan['linkedin_posts'])->toHaveCount(16)
        ->and($campaignPlan['republishing_cadence']['cadence'])->toBe('weekly')
        ->and($campaignPlan['interlink_map']['model'])->toBe('hub_and_spoke_plus_lateral_decision_links')
        ->and($campaignPlan['cta_strategy']['decision'])->toBe('Book an Agentic Marketing demo')
        ->and($campaignPlan['geo_optimization']['tasks'])->toContain('Generate answer blocks for each article.')
        ->and($campaignPlan['ai_visibility_monitoring']['metrics'])->toContain('AI discoverability', 'answer readiness', 'citation likelihood', 'competitor overlap', 'freshness decay');
});

it('tracks approvals feedback and publishing readiness', function () {
    [, $user, , , , $opportunity] = makeOpportunityExecutionScope();
    $service = app(OpportunityExecutionPipelineService::class);
    $pipeline = $service->prepare($opportunity, 'semi_autonomous', $user);

    foreach ($pipeline->assets()->get() as $asset) {
        $pipeline = $service->approveAsset($asset, $user, 'Looks good.');
    }

    expect($pipeline->approval_status)->toBe('approved')
        ->and($pipeline->publishing_readiness)->toBe('ready_for_publishing_pipeline')
        ->and($pipeline->pending_approvals_count)->toBe(0)
        ->and(data_get($pipeline->result, 'publishing_readiness.why_not_ready'))->toBe([])
        ->and(data_get($pipeline->result, 'confidence_risk_scores.requires_human_validation'))->toBeFalse()
        ->and($pipeline->feedback()->count())->toBeGreaterThan(0)
        ->and($pipeline->auditLogs()->where('event', 'asset.approved')->count())->toBe($pipeline->assets_count);
});
