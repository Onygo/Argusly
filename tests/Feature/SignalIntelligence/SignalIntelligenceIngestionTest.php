<?php

use App\Enums\SignalCategory;
use App\Enums\SignalEntityType;
use App\Enums\SignalSourceType;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Models\ClientSite;
use App\Models\CompanyIntelligenceProfile;
use App\Models\CompanyProfile;
use App\Models\CompetitorContentItem;
use App\Models\CompetitorContentOpportunity;
use App\Models\CompetitorTopicSignal;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\Organization;
use App\Models\SignalEntity;
use App\Models\SignalEvent;
use App\Models\SignalFeedItem;
use App\Models\SignalMention;
use App\Models\SignalProcessingRun;
use App\Models\SignalSource;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\SignalIntelligence\CompetitorSignalAdapter;
use App\Services\SignalIntelligence\FeedItemNormalizer;
use App\Services\SignalIntelligence\LlmTrackingSignalAdapter;
use App\Services\SignalIntelligence\MentionExtractionService;
use App\Services\SignalIntelligence\SignalEntityResolver;
use App\Services\SignalIntelligence\SignalEventIngestor;
use App\Services\SignalIntelligence\SignalProcessingRunService;
use App\Services\SignalIntelligence\SignalSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

function signalIngestionWorkspace(string $slug = 'signal-ingestion'): array
{
    $organization = Organization::query()->create([
        'name' => 'Signal Ingestion '.$slug,
        'slug' => $slug,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Argusly',
        'display_name' => 'Argusly',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Argusly Site',
        'site_url' => 'https://argusly.test',
        'base_url' => 'https://argusly.test',
        'allowed_domains' => ['argusly.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$organization, $workspace, $site];
}

it('registers supported signal source capabilities and defaults', function (): void {
    $registry = app(SignalSourceRegistry::class);

    expect($registry->isAllowed(SignalSourceType::LLM_TRACKING))->toBeTrue()
        ->and($registry->capabilities('llm_tracking'))->toContain('supports_mentions', 'supports_ai_visibility', 'supports_competitors')
        ->and($registry->capabilities('competitor'))->toContain('supports_competitors', 'supports_topics', 'supports_opportunities')
        ->and($registry->capabilities('manual'))->toContain('supports_mentions', 'supports_events')
        ->and($registry->defaultConfig('rss_feed'))->toHaveKey('requires_url');
});

it('normalizes feed items and resolves entities idempotently', function (): void {
    [, $workspace, $site] = signalIngestionWorkspace('normalizer');

    $normalized = app(FeedItemNormalizer::class)->normalize([
        'title' => '  Argusly Mentioned  ',
        'body' => 'Argusly is visible in AI search.',
        'url' => 'ARGUSLY.test/blog/',
        'published_at' => 'not a date',
        'raw_payload' => ['source' => 'manual'],
    ]);

    expect($normalized['url'])->toBe('https://argusly.test/blog')
        ->and($normalized['url_hash'])->toBe(hash('sha256', 'https://argusly.test/blog'))
        ->and($normalized['content_hash'])->not->toBeEmpty()
        ->and($normalized['published_at'])->toBeNull()
        ->and($normalized['fetched_at'])->not->toBeNull()
        ->and($normalized['processing_status'])->toBe(SignalStatus::NEW->value);

    $resolver = app(SignalEntityResolver::class);
    $first = $resolver->resolve($workspace, SignalEntityType::BRAND->value, 'Argusly', $site);
    $second = $resolver->resolve($workspace, SignalEntityType::BRAND->value, 'Argusly', $site);

    expect($second->id)->toBe($first->id)
        ->and($second->organization_id)->toBe($workspace->organization_id)
        ->and(SignalEntity::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('extracts deterministic mentions and processes a feed item idempotently', function (): void {
    [, $workspace, $site] = signalIngestionWorkspace('feed-processing');

    CompanyProfile::query()->create([
        'workspace_id' => $workspace->id,
        'company_name' => 'Argusly',
        'key_services' => "AI visibility\ncontent intelligence",
    ]);

    SiteCompetitor::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'CompetitorOS',
        'domain' => 'competitorios.test',
        'is_active' => true,
    ]);

    $source = SignalSource::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => SignalSourceType::MANUAL->value,
    ]);

    $feedItem = SignalFeedItem::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'signal_source_id' => $source->id,
        'title' => 'Argusly and CompetitorOS in AI visibility',
        'body' => 'Argusly, CompetitorOS and argusly.test are discussed in AI visibility.',
    ]);

    $payloads = app(MentionExtractionService::class)->extract($feedItem);

    expect($payloads->pluck('mention_type')->all())->toContain(SignalMention::TYPE_BRAND, SignalMention::TYPE_COMPETITOR, SignalMention::TYPE_TOPIC, SignalMention::TYPE_SOURCE)
        ->and($payloads->pluck('dedupe_hash')->unique()->count())->toBe($payloads->count());

    $this->artisan('signal-intelligence:process-feed-item', ['id' => $feedItem->id])->assertSuccessful();
    $this->artisan('signal-intelligence:process-feed-item', ['id' => $feedItem->id])->assertSuccessful();

    expect(SignalMention::query()->where('workspace_id', $workspace->id)->count())->toBe($payloads->count())
        ->and(SignalEvent::query()->where('workspace_id', $workspace->id)->count())->toBe($payloads->count())
        ->and($feedItem->refresh()->processing_status)->toBe(SignalStatus::RESOLVED);
});

it('ingests events and processing runs idempotently', function (): void {
    [, $workspace, $site] = signalIngestionWorkspace('event-runs');

    $ingestor = app(SignalEventIngestor::class);
    $payload = [
        'category' => SignalCategory::BRAND_VISIBILITY->value,
        'type' => SignalType::BRAND_MENTIONED->value,
        'entity_name' => 'Argusly',
        'entity_key' => 'argusly',
        'signal_strength' => 88,
        'confidence_score' => 91,
        'dedupe_hash' => 'manual-event-hash',
    ];

    $first = $ingestor->ingestEvent($workspace, $payload, $site);
    $second = $ingestor->ingestEvent($workspace, $payload, $site);

    expect($second->id)->toBe($first->id)
        ->and($first->organization_id)->toBe($workspace->organization_id)
        ->and(SignalEvent::query()->where('workspace_id', $workspace->id)->count())->toBe(1);

    $runService = app(SignalProcessingRunService::class);
    $run = $runService->startRun($workspace, 'manual-feed-test', input: ['feed_item_id' => 'abc']);

    expect($run)->toBeInstanceOf(SignalProcessingRun::class)
        ->and($run->status)->toBe(SignalStatus::PROCESSING);

    $finished = $runService->markSucceeded($run, ['items_seen' => 2, 'items_created' => 1, 'signals_created' => 1]);

    expect($finished->status)->toBe(SignalStatus::RESOLVED)
        ->and($finished->items_seen)->toBe(2)
        ->and($finished->signals_created)->toBe(1)
        ->and($finished->hasFinished())->toBeTrue();
});

it('adapts llm tracking runs into mentions and ai visibility events without mutating source data', function (): void {
    [, $workspace, $site] = signalIngestionWorkspace('llm-adapter');

    $query = LlmTrackingQuery::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'AI visibility query',
        'query_text' => 'best AI visibility tools',
        'target_brand' => 'Argusly',
        'target_domain' => 'argusly.test',
        'frequency' => 'weekly',
        'is_active' => true,
    ]);

    $run = LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $query->id,
        'run_at' => now(),
        'provider' => 'openai',
        'model' => 'test-model',
        'status' => 'succeeded',
        'answer_text' => 'Argusly and CompetitorOS are mentioned.',
        'brand_mentioned' => true,
        'competitors_mentioned' => true,
        'competitor_hits' => [['name' => 'CompetitorOS']],
        'first_mention_context' => 'Argusly appears in the first answer block.',
        'sources' => [['url' => 'https://example.test/source']],
        'ai_visibility_score' => 76,
        'model_confidence_score' => 84,
    ]);

    $stats = app(LlmTrackingSignalAdapter::class)->ingest($workspace);
    $again = app(LlmTrackingSignalAdapter::class)->ingest($workspace);

    expect($stats['runs_seen'])->toBe(1)
        ->and($stats['mentions_created'])->toBe(2)
        ->and($stats['events_created'])->toBe(3)
        ->and($again['mentions_created'])->toBe(0)
        ->and(SignalMention::query()->where('workspace_id', $workspace->id)->where('source_ref_id', (string) $run->id)->count())->toBe(2)
        ->and(SignalEvent::query()->where('workspace_id', $workspace->id)->where('category', SignalCategory::AI_VISIBILITY->value)->count())->toBe(1)
        ->and($run->refresh()->answer_text)->toBe('Argusly and CompetitorOS are mentioned.');
});

it('adapts competitor content, topic signals and opportunities defensively', function (): void {
    [, $workspace, $site] = signalIngestionWorkspace('competitor-adapter');

    $competitor = SiteCompetitor::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'CompetitorOS',
        'domain' => 'competitorios.test',
        'is_active' => true,
    ]);

    CompetitorContentItem::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'site_competitor_id' => $competitor->id,
    ]);

    CompetitorTopicSignal::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'site_competitor_id' => $competitor->id,
        'topic' => 'AI visibility implementation',
    ]);

    CompetitorContentOpportunity::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'site_competitor_id' => $competitor->id,
        'topic' => 'AI visibility implementation',
    ]);

    $stats = app(CompetitorSignalAdapter::class)->ingest($workspace);
    $again = app(CompetitorSignalAdapter::class)->ingest($workspace);

    expect($stats['content_items_seen'])->toBe(1)
        ->and($stats['topic_signals_seen'])->toBe(1)
        ->and($stats['opportunities_seen'])->toBe(1)
        ->and($stats['mentions_created'])->toBe(1)
        ->and($stats['events_created'])->toBe(3)
        ->and($again['mentions_created'])->toBe(0)
        ->and($again['events_created'])->toBe(0)
        ->and(SignalEvent::query()->where('workspace_id', $workspace->id)->where('category', SignalCategory::OPPORTUNITY->value)->count())->toBe(1);
});

it('keeps workspace isolation and respects disabled feature flag in commands', function (): void {
    [, $workspace, $site] = signalIngestionWorkspace('command-enabled');
    [, $otherWorkspace] = signalIngestionWorkspace('command-other');

    $query = LlmTrackingQuery::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Workspace filtered query',
        'query_text' => 'argusly visibility',
        'target_brand' => 'Argusly',
        'frequency' => 'weekly',
        'is_active' => true,
    ]);

    LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $query->id,
        'run_at' => now(),
        'status' => 'succeeded',
        'answer_text' => 'Argusly is visible.',
        'brand_mentioned' => true,
        'ai_visibility_score' => 80,
    ]);

    Config::set('features.signal_intelligence', false);
    $this->artisan('signal-intelligence:ingest-llm-tracking', ['--workspace' => $workspace->id])->assertSuccessful();
    expect(SignalEvent::query()->count())->toBe(0);

    Config::set('features.signal_intelligence', true);
    $this->artisan('signal-intelligence:ingest-llm-tracking', ['--workspace' => $workspace->id])->assertSuccessful();

    expect(SignalEvent::query()->where('workspace_id', $workspace->id)->count())->toBe(2)
        ->and(SignalEvent::query()->where('workspace_id', $otherWorkspace->id)->count())->toBe(0);
});
