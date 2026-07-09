<?php

use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Campaign;
use App\Models\ClientSite;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Content;
use App\Models\MarketingInitiative;
use App\Models\MarketingObjective;
use App\Models\MarketingObservation;
use App\Models\MarketingOperatingLink;
use App\Models\MarketingWorkflow;
use App\Models\MonitoredPage;
use App\Models\Organization;
use App\Models\PageIntelligenceReport;
use App\Models\ScheduledPageIntelligenceBriefing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\Intelligence\MarketingEvidence;
use App\Services\AgenticMarketing\Intelligence\MarketingRecommendation;
use App\Services\Mos\OperatingSystem\MarketingOperatingSystem;
use App\Services\Mos\OperatingSystem\MarketingResourceLinker;
use App\Services\PageIntelligence\Reports\ReportBuilder;
use App\Support\Interaction\Action;
use App\Support\Interaction\ActionContext;
use App\Support\Interaction\AppInteractionRegistry;
use App\Support\Interaction\ResourceContext;
use App\Support\Interaction\ResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function marketingOperatingSystemTenant(string $slug = 'marketing-operating-system'): array
{
    $organization = Organization::query()->create([
        'name' => Str::headline($slug).' Org',
        'slug' => $slug.'-'.Str::lower(Str::random(8)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => Str::headline($slug).' Workspace',
        'display_name' => Str::headline($slug).' Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => Str::headline($slug).' Site',
        'site_url' => 'https://mos.example.com',
        'base_url' => 'https://mos.example.com',
        'allowed_domains' => ['mos.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'admin',
        'active' => true,
        'approved_at' => now(),
    ]);

    return compact('organization', 'workspace', 'site', 'user');
}

function marketingOperatingSystemObjective(array $context): MarketingObjective
{
    return app(MarketingOperatingSystem::class)->createObjective($context['workspace'], [
        'client_site_id' => $context['site']->id,
        'name' => 'Grow AI visibility authority',
        'description' => 'Coordinate canonical intelligence into operating priorities.',
        'desired_outcome' => 'Increase qualified AI visibility and organic demand.',
        'priority' => MarketingObjective::PRIORITY_HIGH,
        'target_metric_key' => 'ai_visibility_score',
        'target_value' => 80,
        'market_pack_key' => 'agentic_saas',
        'topics_json' => ['AI visibility'],
        'entities_json' => ['Argusly'],
        'channels_json' => ['Organic Search', 'AI Visibility'],
    ]);
}

function marketingOperatingSystemInitiative(MarketingObjective $objective): MarketingInitiative
{
    return app(MarketingOperatingSystem::class)->createInitiative($objective, [
        'name' => 'Refresh AI visibility content cluster',
        'summary' => 'Improve pages, publish social support, and connect reporting loops.',
        'priority' => MarketingObjective::PRIORITY_HIGH,
        'topics_json' => ['AI visibility'],
        'channels_json' => ['Organic Search', 'Social', 'AI Visibility'],
        'competitors_json' => ['Example Competitor'],
    ]);
}

function marketingOperatingSystemCampaign(array $context): Campaign
{
    return Campaign::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'name' => 'AI Visibility Growth Campaign',
        'objective' => 'Grow AI visibility authority.',
        'status' => 'planning',
        'metadata' => ['planning_source' => 'marketing_operating_system'],
    ]);
}

function marketingOperatingSystemContent(array $context): Content
{
    return Content::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'title' => 'AI visibility strategy for operators',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'primary_keyword' => 'AI visibility strategy',
        'published_url' => 'https://mos.example.com/ai-visibility-strategy',
    ]);
}

function marketingOperatingSystemPage(array $context): MonitoredPage
{
    $url = 'https://mos.example.com/ai-visibility-strategy';

    return MonitoredPage::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'canonical_url' => $url,
        'canonical_url_hash' => hash('sha256', $url),
        'first_seen_url' => $url,
        'first_seen_url_hash' => hash('sha256', $url),
        'final_url' => $url,
        'final_url_hash' => hash('sha256', $url),
        'domain' => 'mos.example.com',
        'path' => '/ai-visibility-strategy',
        'source_type' => 'manual',
        'page_type' => 'article',
        'title_current' => 'AI visibility strategy for operators',
        'first_seen_at' => Carbon::parse('2026-07-01 09:00:00'),
        'last_seen_at' => Carbon::parse('2026-07-08 09:00:00'),
        'crawl_status' => MonitoredPage::CRAWL_STATUS_FETCHED,
        'metadata_json' => [],
    ]);
}

function marketingOperatingSystemObservation(array $context, string $providerKey, string $metricKey, float $value, string $date, array $dimensions): MarketingObservation
{
    $provider = ConnectorProvider::query()->firstOrCreate(
        ['provider_key' => $providerKey],
        [
            'name' => Str::headline($providerKey),
            'category' => 'analytics',
            'status' => ConnectorProvider::STATUS_ACTIVE,
            'supports_sync' => true,
        ]
    );

    $account = ConnectorAccount::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $provider->id,
        'provider_key' => $providerKey,
        'account_name' => Str::headline($providerKey).' Account',
        'external_account_id' => $providerKey.'-'.$date.'-'.$metricKey,
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => Carbon::parse($date.' 08:00:00'),
        'metadata_json' => ['provider_key' => $providerKey],
    ]);

    $dataset = ConnectorDataset::query()->create([
        'connector_account_id' => $account->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'provider_key' => $providerKey,
        'dataset_key' => $metricKey.'-'.$date,
        'dataset_type' => 'canonical_metric',
        'display_name' => Str::headline($metricKey),
        'status' => ConnectorDataset::STATUS_ACTIVE,
        'capabilities_json' => ['keys' => ['marketing_observations']],
        'metadata_json' => ['provider_key' => $providerKey],
    ]);

    return MarketingObservation::upsertByFingerprint([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $provider->id,
        'connector_account_id' => $account->id,
        'connector_dataset_id' => $dataset->id,
        'metric_key' => $metricKey,
        'metric_value' => $value,
        'unit' => str_contains($metricKey, 'rate') || str_contains($metricKey, 'score') ? 'score' : 'count',
        'period_start' => Carbon::parse($date)->startOfDay(),
        'period_end' => Carbon::parse($date)->endOfDay(),
        'granularity' => MarketingObservation::GRANULARITY_DAILY,
        'observed_at' => Carbon::parse($date.' 12:00:00'),
        'confidence_score' => 0.86,
        'quality_score' => 0.91,
        'source_metadata_json' => [
            'provider_key' => $providerKey,
            'connector_account_id' => (string) $account->id,
            'source' => 'canonical_test_fixture',
        ],
    ], collect($dimensions)
        ->map(fn (mixed $value, string $key): array => [
            'dimension_key' => $key,
            'dimension_value' => (string) $value,
        ])
        ->values()
        ->all());
}

function marketingOperatingSystemReport(array $context, ScheduledPageIntelligenceBriefing $briefing): PageIntelligenceReport
{
    return PageIntelligenceReport::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'market_pack_key' => 'agentic_saas',
        'report_type' => ReportBuilder::TYPE_WEEKLY,
        'identity_hash' => hash('sha256', 'marketing-operating-system-report'),
        'title' => 'Marketing Operating System Weekly Brief',
        'status' => PageIntelligenceReport::STATUS_GENERATED,
        'snapshot_version' => 1,
        'template_version' => ReportBuilder::TEMPLATE_VERSION,
        'period_start' => Carbon::parse('2026-07-01')->startOfDay(),
        'period_end' => Carbon::parse('2026-07-08')->endOfDay(),
        'summary' => 'AI visibility is improving while engagement needs review.',
        'payload_json' => ['sections' => ['operating_system' => true]],
        'provenance_json' => ['source' => 'test'],
        'generated_by' => $context['user']->id,
        'generated_at' => Carbon::parse('2026-07-08 13:00:00'),
        'artifact_type' => PageIntelligenceReport::ARTIFACT_TYPE_PDF,
        'artifact_status' => PageIntelligenceReport::ARTIFACT_STATUS_PENDING,
        'scheduled_page_intelligence_briefing_id' => $briefing->id,
    ]);
}

function marketingOperatingSystemBriefing(array $context): ScheduledPageIntelligenceBriefing
{
    return ScheduledPageIntelligenceBriefing::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'report_type' => ReportBuilder::TYPE_WEEKLY,
        'market_pack_key' => 'agentic_saas',
        'frequency' => ScheduledPageIntelligenceBriefing::FREQUENCY_WEEKLY,
        'day_of_week' => 1,
        'timezone' => 'UTC',
        'recipients_json' => ['ops@example.test'],
        'delivery_channels_json' => ['email_placeholder'],
        'delivery_state_json' => [],
        'is_active' => true,
        'next_run_at' => Carbon::parse('2026-07-13 09:00:00'),
        'created_by' => $context['user']->id,
    ]);
}

it('manages objective lifecycle with operating timeline audit events', function (): void {
    $context = marketingOperatingSystemTenant('mos-objective-lifecycle');
    $mos = app(MarketingOperatingSystem::class);
    $theme = $mos->objectives->createTheme($context['workspace'], [
        'client_site_id' => $context['site']->id,
        'name' => 'AI Visibility Theme',
        'priority' => MarketingObjective::PRIORITY_HIGH,
        'market_pack_key' => 'agentic_saas',
    ]);

    $objective = $mos->createObjective($context['workspace'], [
        'client_site_id' => $context['site']->id,
        'marketing_theme_id' => $theme->id,
        'name' => 'Grow qualified AI visibility',
        'desired_outcome' => 'More qualified demand from AI visibility and organic search.',
        'priority' => MarketingObjective::PRIORITY_HIGH,
        'target_metric_key' => 'sessions',
        'market_pack_key' => 'agentic_saas',
    ]);

    $mos->transitionObjective($objective, MarketingObjective::STATUS_ACTIVE, $context['user'], ['reason' => 'operating cadence started']);
    $mos->transitionObjective($objective, MarketingObjective::STATUS_COMPLETED, $context['user'], ['reason' => 'target achieved']);

    $objective->refresh();
    $events = $objective->timelineEvents()->orderBy('created_at')->pluck('event_type')->all();

    expect($objective->status)->toBe(MarketingObjective::STATUS_COMPLETED)
        ->and((string) $objective->marketing_theme_id)->toBe((string) $theme->id)
        ->and($events)->toContain('objective.created', 'objective.status_changed');
});

it('manages initiative lifecycle workflows reviews and priorities', function (): void {
    $context = marketingOperatingSystemTenant('mos-initiative-lifecycle');
    $mos = app(MarketingOperatingSystem::class);
    $objective = marketingOperatingSystemObjective($context);
    $initiative = marketingOperatingSystemInitiative($objective);

    $mos->transitionInitiative($initiative, MarketingInitiative::STATUS_ACTIVE, $context['user']);
    $workflow = $mos->workflows->create($initiative, [
        'workflow_key' => 'campaign_planning',
        'name' => 'Campaign planning workflow',
        'current_stage' => 'draft_plan',
        'stages_json' => ['draft_plan', 'review', 'ready'],
    ]);
    $mos->workflows->advance($workflow, 'review', MarketingWorkflow::STATUS_ACTIVE, ['gate' => 'editorial']);
    $review = $mos->reviews->request($initiative, [
        'review_type' => 'operating_review',
        'summary' => 'Review content cluster plan before execution.',
        'evidence_json' => ['initiative_id' => (string) $initiative->id],
    ]);
    $mos->reviews->decide($review, 'approved', $context['user'], 'Approved for execution.');
    $priority = $mos->priorities->create($initiative, [
        'name' => 'Fix engagement drop on AI visibility article',
        'priority_level' => MarketingObjective::PRIORITY_HIGH,
        'priority_score' => 82,
        'confidence_score' => 0.84,
        'reason' => 'Traffic is rising while engagement is falling.',
    ]);

    $graph = $mos->graphForInitiative($initiative->fresh());

    expect($initiative->fresh()->status)->toBe(MarketingInitiative::STATUS_ACTIVE)
        ->and($workflow->fresh()->current_stage)->toBe('review')
        ->and($review->fresh()->status)->toBe('approved')
        ->and($priority->fresh()->priority_score)->toBe(82)
        ->and(collect($graph['timeline'])->pluck('event_type')->all())->toContain('initiative.created', 'workflow.created', 'workflow.advanced', 'review.requested', 'review.decided', 'priority.created');
});

it('connects objectives initiatives campaigns content pages performance recommendations reports and briefings', function (): void {
    $context = marketingOperatingSystemTenant('mos-operating-chain');
    $mos = app(MarketingOperatingSystem::class);
    $objective = marketingOperatingSystemObjective($context);
    $initiative = marketingOperatingSystemInitiative($objective);
    $campaign = marketingOperatingSystemCampaign($context);
    $content = marketingOperatingSystemContent($context);
    $page = marketingOperatingSystemPage($context);

    $observation = marketingOperatingSystemObservation($context, 'google_analytics_4', 'sessions', 250, '2026-07-08', [
        'pagePath' => '/ai-visibility-strategy',
        'defaultChannelGroup' => 'Organic Search',
        'topic' => 'AI visibility',
        'market_pack' => 'agentic_saas',
    ]);
    marketingOperatingSystemObservation($context, 'google_search_console', 'impressions', 1200, '2026-07-08', [
        'pagePath' => '/ai-visibility-strategy',
        'defaultChannelGroup' => 'Organic Search',
        'topic' => 'AI visibility',
        'market_pack' => 'agentic_saas',
    ]);

    $recommendation = new MarketingRecommendation(
        key: 'improve-ai-visibility-article',
        type: 'performance_opportunity',
        title: 'Improve AI visibility article',
        summary: 'Traffic is rising while engagement and AI visibility need support.',
        priority: 88,
        confidence: 0.82,
        evidence: new MarketingEvidence(
            marketingObservationIds: [(string) $observation->id],
            trendIds: ['sessions:2026-07-01:2026-07-08'],
            performanceSignalKeys: ['traffic_rising_engagement_falling'],
            sourceMetrics: ['sessions' => 250],
        ),
        recommendedActions: ['Refresh article', 'Publish LinkedIn support'],
        affectedPages: [['id' => (string) $page->id, 'url' => $page->canonical_url]],
        affectedTopics: ['AI visibility'],
        affectedChannels: ['Organic Search', 'AI Visibility'],
        affectedCompetitors: ['Example Competitor'],
        marketPackContext: ['market_pack_key' => 'agentic_saas'],
    );

    $briefing = marketingOperatingSystemBriefing($context);
    $report = marketingOperatingSystemReport($context, $briefing);

    $mos->linkResource($initiative, $campaign, MarketingOperatingLink::RELATION_DRIVES);
    $mos->linkResource($initiative, $content, MarketingOperatingLink::RELATION_SUPPORTS);
    $mos->linkResource($initiative, $page, MarketingOperatingLink::RELATION_SUPPORTS);
    $mos->snapshotPerformance($initiative, $context['site'], '2026-07-01', '2026-07-08');
    $mos->linkRecommendation($initiative, $recommendation);
    $mos->integrateReport($initiative, $report);
    $mos->integrateBriefing($initiative, $briefing);

    $graph = $mos->graph($objective->fresh());

    expect($graph['chain'])->toMatchArray([
        'objectives' => 1,
        'initiatives' => 1,
        'campaigns' => 1,
        'content' => 1,
        'pages' => 1,
        'performance' => 1,
        'recommendations' => 1,
        'reports' => 1,
        'briefings' => 1,
    ])
        ->and($graph['resources'][MarketingResourceLinker::RESOURCE_AGENTIC_RECOMMENDATION][0]['metadata']['evidence']['marketing_observation_ids'])->toBe([(string) $observation->id])
        ->and($graph['resources'][MarketingResourceLinker::RESOURCE_PAGE_INTELLIGENCE_REPORT][0]['resource_id'])->toBe((string) $report->id)
        ->and($graph['resources'][MarketingResourceLinker::RESOURCE_SCHEDULED_BRIEFING][0]['resource_id'])->toBe((string) $briefing->id);
});

it('projects resource metadata and drawer backed command metadata through Universal UI', function (): void {
    $context = marketingOperatingSystemTenant('mos-universal-ui');
    $mos = app(MarketingOperatingSystem::class);
    $objective = marketingOperatingSystemObjective($context);
    $initiative = marketingOperatingSystemInitiative($objective);
    $workflow = $mos->workflows->create($initiative, [
        'workflow_key' => 'approval_workflow',
        'name' => 'Approval workflow',
        'current_stage' => 'review',
    ]);

    $resourceRegistry = AppInteractionRegistry::resourceRegistryFor([$objective, $initiative, $workflow]);
    $actionRegistry = AppInteractionRegistry::actionRegistry();

    $resourceRegistry->assertAllTypesMapToExistingReferences();
    $resourceRegistry->assertAllResourcesMapToExistingReferences();
    $resourceRegistry->assertAvailableActionsExist($actionRegistry);
    $actionRegistry->assertAllActionsMapToEndpoints();

    $contextForResources = ResourceContext::make(['user' => $context['user']]);
    $objectiveResource = $resourceRegistry->resolve(ResourceType::MARKETING_OBJECTIVE.':'.$objective->id, $contextForResources);
    $initiativeResource = $resourceRegistry->resolve(ResourceType::MARKETING_INITIATIVE.':'.$initiative->id, $contextForResources);

    $objectiveAction = $actionRegistry->get('app.marketing-objective.inspect')->resolve(ActionContext::make([
        'user' => $context['user'],
        'surface' => Action::SURFACE_COMMAND_PALETTE,
        'resource_type' => ResourceType::MARKETING_OBJECTIVE,
        'resource_id' => $objective->id,
        'metadata' => ['subject' => $objective],
    ]));

    expect($resourceRegistry->hasType(ResourceType::MARKETING_OBJECTIVE))->toBeTrue()
        ->and($objectiveResource['drawer']['target'])->toBe('marketing-objective.inspect')
        ->and($objectiveResource['available_actions'])->toBe(['app.marketing-objective.inspect'])
        ->and($objectiveResource['metadata']['dashboard'])->toBeFalse()
        ->and($initiativeResource['relationships'][0]['resource_type'])->toBe(ResourceType::MARKETING_OBJECTIVE)
        ->and($objectiveAction['drawer']['target'])->toBe('marketing-objective.inspect')
        ->and($objectiveAction['route'])->toBeNull()
        ->and($objectiveAction['metadata']['dashboard'])->toBeFalse()
        ->and($objectiveAction['visible'])->toBeTrue();
});

it('links canonical observations provider agnostically without leaking connector metadata', function (): void {
    $context = marketingOperatingSystemTenant('mos-provider-agnostic');
    $mos = app(MarketingOperatingSystem::class);
    $objective = marketingOperatingSystemObjective($context);
    $initiative = marketingOperatingSystemInitiative($objective);

    $ga4 = marketingOperatingSystemObservation($context, 'google_analytics_4', 'sessions', 320, '2026-07-08', [
        'pagePath' => '/ai-visibility-strategy',
        'defaultChannelGroup' => 'Organic Search',
        'topic' => 'AI visibility',
        'market_pack' => 'agentic_saas',
    ]);
    $linkedin = marketingOperatingSystemObservation($context, 'linkedin_analytics', 'engagement_rate', 0.34, '2026-07-08', [
        'pagePath' => '/ai-visibility-strategy',
        'defaultChannelGroup' => 'Social',
        'topic' => 'AI visibility',
        'market_pack' => 'agentic_saas',
    ]);

    $links = $mos->linkObservations($initiative, [$ga4, $linkedin]);
    $graph = $mos->graph($objective->fresh());
    $observationPayload = json_encode($graph['resources'][MarketingResourceLinker::RESOURCE_MARKETING_OBSERVATION], JSON_THROW_ON_ERROR);

    expect($links)->toHaveCount(2)
        ->and($observationPayload)->toContain('sessions', 'engagement_rate')
        ->and($observationPayload)->not->toContain('google_analytics_4')
        ->and($observationPayload)->not->toContain('linkedin_analytics')
        ->and($observationPayload)->not->toContain('connector_account_id')
        ->and($observationPayload)->not->toContain('connector_dataset_id')
        ->and(collect($graph['links'])->pluck('relationship_type')->all())->toContain(MarketingOperatingLink::RELATION_EVIDENCES);
});

it('links stored agentic opportunities into the operating graph', function (): void {
    $context = marketingOperatingSystemTenant('mos-agentic-linking');
    $mos = app(MarketingOperatingSystem::class);
    $objective = marketingOperatingSystemObjective($context);
    $initiative = marketingOperatingSystemInitiative($objective);
    $agenticObjective = AgenticMarketingObjective::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'name' => 'Agentic MOS objective',
        'goal' => 'Coordinate agentic opportunities into operating work.',
        'locale' => 'en',
        'status' => 'active',
    ]);
    $opportunity = AgenticMarketingOpportunity::query()->create([
        'objective_id' => $agenticObjective->id,
        'title' => 'Improve AI visibility cluster',
        'type' => 'ai_visibility',
        'priority_score' => 87,
        'status' => 'open',
        'payload' => [
            'topic' => 'AI visibility',
            'client_site_id' => (string) $context['site']->id,
            'supporting_observations' => ['observation-1'],
        ],
    ]);

    $link = $mos->linkRecommendation($initiative, $opportunity);

    expect($link->relationship_type)->toBe(MarketingOperatingLink::RELATION_RECOMMENDS)
        ->and($link->resource_type)->toBe(MarketingResourceLinker::RESOURCE_AGENTIC_RECOMMENDATION)
        ->and($link->metadata_json['source'])->toBe('agentic_marketing_opportunity')
        ->and($mos->graphForInitiative($initiative->fresh())['resources'][MarketingResourceLinker::RESOURCE_AGENTIC_RECOMMENDATION][0]['resource_id'])->toBe((string) $opportunity->id);
});
