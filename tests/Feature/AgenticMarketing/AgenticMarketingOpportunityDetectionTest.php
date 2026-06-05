<?php

use App\Enums\AgenticMarketingOpportunityType;
use App\Enums\ContentDecayRiskLevel;
use App\Enums\ContentLifecycleStatus;
use App\Jobs\AgenticMarketing\DetectAgenticMarketingOpportunitiesJob;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingRun;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentAiVisibilitySnapshot;
use App\Models\ContentCluster;
use App\Models\ContentIndexationHealth;
use App\Models\LinkOpportunity;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\LlmTrackingQuerySet;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingDecisionEngine;
use App\Services\AgenticMarketing\AgenticMarketingOpportunityDetectionService;
use App\Services\AgenticMarketing\OpportunityDetection\DetectedOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeOpportunityDetectionTenant(string $slug = 'am-detect'): array
{
    $org = Organization::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => $slug . ' workspace',
        'organization_id' => $org->id,
        'enabled_content_languages' => ['en', 'nl'],
        'default_content_language' => 'en',
    ]);

    $user = User::factory()->create([
        'organization_id' => $org->id,
        'role' => 'admin',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$org, $workspace, $user];
}

function makeOpportunityDetectionObjective(Organization $org, Workspace $workspace, array $attributes = []): AgenticMarketingObjective
{
    return AgenticMarketingObjective::query()->create(array_merge([
        'organization_id' => $org->id,
        'workspace_id' => $workspace->id,
        'name' => 'Deterministic opportunity engine',
        'goal' => 'Find stored-signal content growth opportunities.',
        'locale' => 'en',
        'languages' => ['en', 'nl'],
        'kpi_type' => 'ai_visibility',
        'approval_mode' => 'manual',
        'status' => 'active',
    ], $attributes));
}

function makeOpportunityDetectionContent(Workspace $workspace, array $attributes = []): Content
{
    return Content::query()->create(array_merge([
        'workspace_id' => $workspace->id,
        'title' => 'Teams analytics guide',
        'type' => 'article',
        'status' => 'published',
        'source' => 'api',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
        'language' => 'en',
        'publish_status' => 'published',
        'published_url' => 'https://example.test/' . Str::slug((string) ($attributes['title'] ?? 'teams analytics guide')),
    ], $attributes));
}

it('detects ranked deduped opportunities from stored intelligence signals', function () {
    [$org, $workspace] = makeOpportunityDetectionTenant();
    $objective = makeOpportunityDetectionObjective($org, $workspace);

    $content = makeOpportunityDetectionContent($workspace, [
        'title' => 'Teams analytics guide',
        'freshness_score' => 35,
        'content_health_score' => 58,
        'optimization_opportunity_score' => 72,
        'decay_risk_level' => ContentDecayRiskLevel::HIGH->value,
        'lifecycle_stage' => ContentLifecycleStatus::REFRESH_NEEDED->value,
        'answer_block_score' => 20,
        'answer_block_generation_persisted_count' => 0,
        'ai_visibility_score' => 32,
        'aeo_score' => 41,
        'semantic_coverage_score' => 45,
        'seo_title' => null,
        'seo_meta_description' => null,
        'schema_type' => null,
        'robots_index' => false,
    ]);

    $target = makeOpportunityDetectionContent($workspace, [
        'title' => 'Revenue operations dashboard',
        'freshness_score' => 90,
        'content_health_score' => 90,
        'optimization_opportunity_score' => 10,
        'ai_visibility_score' => 80,
        'answer_block_score' => 90,
        'answer_block_generation_persisted_count' => 3,
        'seo_title' => 'Revenue operations dashboard',
        'seo_meta_description' => 'A complete dashboard guide.',
        'schema_type' => 'Article',
        'robots_index' => true,
    ]);

    LinkOpportunity::query()->create([
        'workspace_id' => $workspace->id,
        'source_content_id' => $content->id,
        'target_content_id' => $target->id,
        'anchor_text_suggestion' => 'revenue operations dashboard',
        'status' => LinkOpportunity::STATUS_SUGGESTED,
        'relevance_score' => 0.92,
        'meta' => ['source' => 'test'],
    ]);

    ContentIndexationHealth::query()->create([
        'content_id' => $content->id,
        'indexed' => false,
        'canonical_accepted' => false,
        'duplicate_detected' => true,
        'redirect_issue' => false,
        'crawled_not_indexed' => true,
        'noindex_detected' => true,
        'sitemap_status' => 'missing',
        'health_score' => 30,
        'canonical_url' => 'https://example.test/teams-analytics-guide',
        'google_selected_canonical' => 'https://example.test/duplicate',
        'issues_json' => ['canonical_mismatch'],
    ]);

    ContentAiVisibilitySnapshot::query()->create([
        'content_id' => $content->id,
        'provider' => 'perplexity',
        'visibility_score' => 25,
        'citation_count' => 0,
        'avg_position' => null,
        'captured_at' => now(),
    ]);

    ContentCluster::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Teams analytics',
        'topic_keyword' => 'teams analytics',
        'pillar_content_id' => null,
        'supporting_content_ids' => [$content->id],
        'cluster_score' => 34,
        'meta' => [],
    ]);

    $first = app(AgenticMarketingOpportunityDetectionService::class)->detect($objective->id);
    $second = app(AgenticMarketingOpportunityDetectionService::class)->detect($objective->id);

    expect($first['created'])->toBeGreaterThanOrEqual(7)
        ->and($second['created'])->toBe(0)
        ->and($second['reused'])->toBe($first['created']);

    $types = AgenticMarketingOpportunity::query()
        ->where('objective_id', $objective->id)
        ->pluck('type')
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($types)->toContain(
        AgenticMarketingOpportunityType::Refresh->value,
        AgenticMarketingOpportunityType::InternalLinks->value,
        AgenticMarketingOpportunityType::LocaleExpansion->value,
        AgenticMarketingOpportunityType::AnswerCoverage->value,
        AgenticMarketingOpportunityType::SeoIndexability->value,
        AgenticMarketingOpportunityType::NewArticle->value,
        AgenticMarketingOpportunityType::ContentNetwork->value,
        AgenticMarketingOpportunityType::AiVisibility->value,
    );

    $scores = AgenticMarketingOpportunity::query()
        ->where('objective_id', $objective->id)
        ->orderByDesc('priority_score')
        ->pluck('priority_score')
        ->all();

    expect($scores)->toBe(collect($scores)->sortDesc()->values()->all());

    $storedOpportunity = AgenticMarketingOpportunity::query()
        ->where('objective_id', $objective->id)
        ->orderByDesc('priority_score')
        ->first();
    $storedExplanation = data_get($storedOpportunity?->payload, 'score_explanation');

    expect($storedExplanation)->toBeArray()
        ->and($storedExplanation)->toHaveKeys([
            'impact_score',
            'effort_score',
            'confidence_score',
            'risk_score',
            'priority_score',
            'formula',
            'reasons',
        ]);

    $orderedIds = (array) data_get($first, 'runs.0.opportunity_ids', []);
    $orderedScores = AgenticMarketingOpportunity::query()
        ->whereIn('id', $orderedIds)
        ->get(['id', 'priority_score'])
        ->keyBy('id');

    expect((int) $orderedScores[$orderedIds[0]]->priority_score)
        ->toBeGreaterThanOrEqual((int) $orderedScores[$orderedIds[array_key_last($orderedIds)]]->priority_score);
});

it('scores higher impact stored-signal opportunities ahead of lower impact work', function () {
    $engine = app(AgenticMarketingDecisionEngine::class);

    $seoIssue = $engine->score(new DetectedOpportunity(
        title: 'Fix noindex and canonical issue',
        type: AgenticMarketingOpportunityType::SeoIndexability,
        priorityScore: 50,
        payload: [
            'detector' => 'test',
            'signals' => [
                'issues' => ['robots_noindex', 'canonical_not_accepted', 'crawled_not_indexed'],
                'health_score' => 20,
            ],
        ],
    ));

    $smallLinkGap = $engine->score(new DetectedOpportunity(
        title: 'Add one internal link',
        type: AgenticMarketingOpportunityType::InternalLinks,
        priorityScore: 50,
        payload: [
            'detector' => 'test',
            'signals' => [
                'suggested_link_count' => 1,
                'link_opportunities' => [
                    ['target_content_id' => 'target-1', 'relevance_score' => 0.55],
                ],
            ],
        ],
    ));

    expect($seoIssue->priorityScore)->toBeGreaterThan($smallLinkGap->priorityScore)
        ->and(data_get($seoIssue->payload, 'score_explanation.formula'))->toContain('impact*0.45')
        ->and(data_get($seoIssue->payload, 'score_explanation.reasons'))->toContain('SEO/indexability checks found stored issue signals.');
});

it('runs detection from the artisan command and can dispatch the queue job', function () {
    [$org, $workspace] = makeOpportunityDetectionTenant('am-command');
    $objective = makeOpportunityDetectionObjective($org, $workspace);

    makeOpportunityDetectionContent($workspace, [
        'title' => 'Lifecycle refresh command test',
        'freshness_score' => 25,
        'lifecycle_stage' => ContentLifecycleStatus::REFRESH_NEEDED->value,
    ]);

    $this->artisan('agentic-marketing:detect-opportunities', ['objective' => $objective->id])
        ->assertExitCode(0);

    expect(AgenticMarketingOpportunity::query()->where('objective_id', $objective->id)->count())->toBeGreaterThan(0)
        ->and(AgenticMarketingRun::query()->where('objective_id', $objective->id)->where('status', 'completed')->exists())->toBeTrue();

    Bus::fake();

    $this->artisan('agentic-marketing:detect-opportunities', [
        'objective' => $objective->id,
        '--queue' => true,
    ])->assertExitCode(0);

    Bus::assertDispatched(DetectAgenticMarketingOpportunitiesJob::class, fn ($job): bool => $job->objectiveId === (string) $objective->id);
});

it('creates deduped opportunities from stored llm tracking visibility signals', function () {
    [$org, $workspace] = makeOpportunityDetectionTenant('am-llm-visibility');
    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'AI visibility site',
        'site_url' => 'https://visibility.example.test',
        'allowed_domains' => ['visibility.example.test'],
        'is_active' => true,
        'status' => 'active',
    ]);
    $objective = makeOpportunityDetectionObjective($org, $workspace, [
        'client_site_id' => $site->id,
        'languages' => ['en', 'nl'],
    ]);
    $content = makeOpportunityDetectionContent($workspace, [
        'client_site_id' => $site->id,
        'title' => 'AI visibility platform comparison',
        'published_url' => 'https://visibility.example.test/ai-visibility-platform-comparison',
        'answer_block_score' => 20,
        'answer_block_generation_persisted_count' => 0,
    ]);
    $set = LlmTrackingQuerySet::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Buyer journey prompts',
        'locale' => 'en',
        'is_active' => true,
    ]);
    $query = LlmTrackingQuery::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'llm_tracking_query_set_id' => $set->id,
        'name' => 'Best AI visibility platforms',
        'query_text' => 'What are the best AI visibility platforms for B2B SaaS?',
        'target_brand' => 'PublishLayer',
        'target_domain' => 'visibility.example.test',
        'brand_terms' => ['PublishLayer'],
        'competitor_terms' => ['CompetitorAI'],
        'target_urls' => ['https://visibility.example.test/ai-visibility-platform-comparison'],
        'tags' => ['buyer'],
        'locale' => 'en',
        'frequency' => 'daily',
        'priority' => 90,
        'is_active' => true,
    ]);
    $run = LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $query->id,
        'run_at' => now(),
        'provider' => 'openai',
        'model' => 'gpt-test',
        'status' => 'succeeded',
        'answer_text' => 'CompetitorAI is often mentioned for AI visibility workflows.',
        'brand_mentioned' => false,
        'urls_cited' => false,
        'competitors_mentioned' => true,
        'brand_hits' => [],
        'competitor_hits' => [['term' => 'CompetitorAI', 'count' => 2]],
        'detected_brands' => [],
        'detected_competitors' => ['CompetitorAI'],
        'entity_presence' => ['PublishLayer' => false],
        'url_hits' => [],
        'citation_ranking' => ['url' => ['bucket' => 'none']],
        'sources' => [['url' => 'https://competitor.example/blog', 'domain' => 'competitor.example', 'type' => 'blog']],
        'detected_domains' => ['competitor.example'],
        'presence_score' => 0.0,
        'citation_score' => 0.0,
        'competitor_share_score' => 0.1,
        'ai_visibility_score' => 0.25,
    ]);
    LlmTrackingQuery::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'llm_tracking_query_set_id' => $set->id,
        'name' => 'NL AI zichtbaarheid',
        'query_text' => 'Welke AI visibility tools zijn er voor SaaS?',
        'target_brand' => 'PublishLayer',
        'brand_terms' => ['PublishLayer'],
        'competitor_terms' => ['CompetitorAI'],
        'target_urls' => [],
        'locale' => 'nl',
        'frequency' => 'daily',
        'priority' => 80,
        'is_active' => true,
    ])->runs()->create([
        'run_at' => now(),
        'provider' => 'openai',
        'model' => 'gpt-test',
        'status' => 'succeeded',
        'answer_text' => 'Geen duidelijke PublishLayer zichtbaarheid.',
        'brand_mentioned' => false,
        'urls_cited' => false,
        'competitors_mentioned' => false,
        'ai_visibility_score' => 0.35,
    ]);

    $this->app->instance(
        \App\Services\Llm\LlmManager::class,
        \Mockery::mock(\App\Services\Llm\LlmManager::class, fn ($mock) => $mock->shouldReceive('generateJson')->never())
    );

    $first = app(AgenticMarketingOpportunityDetectionService::class)->detect($objective->id);
    $second = app(AgenticMarketingOpportunityDetectionService::class)->detect($objective->id);

    expect($first['created'])->toBeGreaterThanOrEqual(6)
        ->and($second['created'])->toBe(0);

    $payloads = AgenticMarketingOpportunity::query()
        ->where('objective_id', $objective->id)
        ->where('payload->detector', 'llm_tracking_ai_visibility')
        ->pluck('payload');

    $signalTypes = $payloads
        ->map(fn (array $payload): string => (string) ($payload['signal_type'] ?? ''))
        ->unique()
        ->values()
        ->all();

    expect($signalTypes)->toContain(
        'weak_brand_entity_visibility',
        'missing_brand_mentions',
        'competitor_dominance',
        'missing_owned_citations',
        'query_set_coverage_gap',
        'locale_ai_visibility_gap',
        'missing_answer_blocks_for_important_query',
    );

    $missingCitation = $payloads->first(fn (array $payload): bool => ($payload['signal_type'] ?? null) === 'missing_owned_citations');
    expect(data_get($missingCitation, 'references.llm_tracking_query_run_id'))->toBe($run->id)
        ->and(data_get($missingCitation, 'references.llm_tracking_query_id'))->toBe($query->id)
        ->and(data_get($missingCitation, 'references.content_urls.0'))->toBe('https://visibility.example.test/ai-visibility-platform-comparison')
        ->and(data_get($missingCitation, 'references.matched_content_id'))->toBe((string) $content->id);
});
