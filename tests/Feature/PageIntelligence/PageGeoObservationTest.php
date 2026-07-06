<?php

use App\Jobs\PageIntelligence\LinkLlmTrackingSourcesToPagesJob;
use App\Models\ClientSite;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\MonitoredPage;
use App\Models\Organization;
use App\Models\PageGeoObservation;
use App\Models\SignalEvent;
use App\Models\Workspace;
use App\Services\PageIntelligence\Geo\PageGeoObservationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates monitored pages from LLM tracking source URLs', function (): void {
    [$workspace, , , $run] = geoObservationRun([
        'sources' => [
            ['url' => 'https://argusly.com/features?utm_source=chatgpt', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 80],
        ],
    ]);

    app(PageGeoObservationBuilder::class)->buildForRun($run);

    $page = MonitoredPage::query()
        ->where('workspace_id', $workspace->id)
        ->where('canonical_url', 'https://argusly.com/features')
        ->first();

    expect($page)->not->toBeNull()
        ->and($page->source_type)->toBe('geo')
        ->and($page->geoObservations()->whereNotNull('cited_url')->exists())->toBeTrue();
});

it('creates PageGeoObservation rows for LLM citations', function (): void {
    [, , , $run] = geoObservationRun([
        'sources' => [
            ['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 80],
            ['url' => 'https://example-news.com/analysis', 'domain' => 'example-news.com', 'type' => 'news', 'position' => 180],
        ],
    ]);

    $observations = app(PageGeoObservationBuilder::class)->buildForRun($run);

    expect($observations)->toHaveCount(3)
        ->and(PageGeoObservation::query()->where('llm_tracking_query_run_id', $run->id)->where('cited_domain', 'argusly.com')->exists())->toBeTrue()
        ->and(PageGeoObservation::query()->where('llm_tracking_query_run_id', $run->id)->whereNull('cited_url')->exists())->toBeTrue();
});

it('rejects unsafe GEO citation URLs before monitored page persistence', function (): void {
    [$workspace, , , $run] = geoObservationRun([
        'sources' => [
            ['url' => 'http://127.0.0.1/private', 'domain' => '127.0.0.1', 'type' => 'website', 'position' => 1],
        ],
        'url_hits' => [],
        'answer_text' => 'Argusly is mentioned without a usable citation.',
        'normalized_response' => 'Argusly is mentioned without a usable citation.',
    ]);

    app(PageGeoObservationBuilder::class)->buildForRun($run);

    expect(MonitoredPage::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(PageGeoObservation::query()->where('workspace_id', $workspace->id)->whereNotNull('cited_url')->count())->toBe(0);
});

it('stores competitor citation pressure from LLM tracking runs', function (): void {
    [, , , $run] = geoObservationRun([
        'sources' => [
            ['url' => 'https://competitor.acmeseo.com/guide', 'domain' => 'competitor.acmeseo.com', 'type' => 'website', 'position' => 60],
        ],
        'competitors_mentioned' => true,
        'competitor_pressure_score' => 0.8,
    ]);

    app(PageGeoObservationBuilder::class)->buildForRun($run);

    $observation = PageGeoObservation::query()
        ->where('llm_tracking_query_run_id', $run->id)
        ->where('cited_domain', 'competitor.acmeseo.com')
        ->firstOrFail();

    expect($observation->competitors_cited)->toBeTrue()
        ->and($observation->mentioned_competitors_json[0]['term'])->toBe('AcmeSEO');
});

it('stores explainable GEO visibility scores', function (): void {
    [, , , $run] = geoObservationRun([
        'sources' => [
            ['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 80],
        ],
        'ai_visibility_score' => 0.92,
        'model_confidence_score' => 0.9,
    ]);

    app(PageGeoObservationBuilder::class)->buildForRun($run);

    $observation = PageGeoObservation::query()
        ->where('llm_tracking_query_run_id', $run->id)
        ->where('cited_domain', 'argusly.com')
        ->firstOrFail();

    expect((float) $observation->geo_visibility_score)->toBeGreaterThan(0)
        ->and($observation->breakdown_json)->toHaveKey('model')
        ->and($observation->breakdown_json)->toHaveKey('weights')
        ->and($observation->breakdown_json['inputs']['client_cited'])->toBeTrue();
});

it('respects provider retention policy for GEO observations', function (): void {
    config()->set('llm_tracking.geo.retention.providers.openai', [
        'policy' => 'summary_80_no_raw',
        'store_answer_summary' => true,
        'max_answer_summary_chars' => 80,
        'store_raw_payload' => false,
    ]);

    [, , , $run] = geoObservationRun([
        'answer_text' => str_repeat('Argusly is cited as a strong source for GEO visibility. ', 12),
        'normalized_response' => str_repeat('Argusly is cited as a strong source for GEO visibility. ', 12),
        'parsed_payload' => ['full_answer' => str_repeat('secret answer ', 50)],
        'sources' => [
            ['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 80],
        ],
    ]);

    app(PageGeoObservationBuilder::class)->buildForRun($run);

    $observation = PageGeoObservation::query()
        ->where('llm_tracking_query_run_id', $run->id)
        ->where('cited_domain', 'argusly.com')
        ->firstOrFail();

    expect($observation->retention_policy)->toBe('summary_80_no_raw')
        ->and(strlen((string) $observation->answer_summary))->toBeLessThanOrEqual(80)
        ->and($observation->raw_payload_json)->not->toHaveKey('parsed_payload')
        ->and(data_get($observation->raw_payload_json, 'retention'))->toBe('raw_answer_omitted');
});

it('emits a signal when the client gains a GEO citation', function (): void {
    [$workspace, , $query, $previousRun] = geoObservationRun([
        'run_at' => Carbon::parse('2026-07-02 09:00:00'),
        'sources' => [
            ['url' => 'https://competitor.acmeseo.com/guide', 'domain' => 'competitor.acmeseo.com', 'type' => 'website', 'position' => 60],
        ],
        'url_hits' => [],
        'brand_mentioned' => false,
    ]);

    app(PageGeoObservationBuilder::class)->buildForRun($previousRun);

    $currentRun = LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $query->id,
        'run_at' => Carbon::parse('2026-07-03 09:00:00'),
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'succeeded',
        'answer_text' => 'Argusly is cited via https://argusly.com/features.',
        'normalized_response' => 'Argusly is cited via https://argusly.com/features.',
        'brand_hits' => [['term' => 'Argusly', 'count' => 1, 'bucket' => 'first']],
        'competitor_hits' => [],
        'detected_brands' => [['term' => 'Argusly', 'type' => 'brand', 'present' => true]],
        'detected_competitors' => [],
        'sources' => [['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 80]],
        'brand_mentioned' => true,
        'urls_cited' => true,
        'competitors_mentioned' => false,
        'presence_score' => 1,
        'position_score' => 1,
        'sentiment_score' => 0.7,
        'sentiment_label' => 'positive',
        'competitive_score' => 1,
        'ai_visibility_score' => 0.9,
    ]);

    app(PageGeoObservationBuilder::class)->buildForRun($currentRun);

    $event = SignalEvent::query()->get()->first(
        fn (SignalEvent $event): bool => data_get($event->metadata, 'source') === 'page_intelligence_geo'
            && data_get($event->metadata, 'event_key') === 'client_gained_citation'
    );

    expect($event)->not->toBeNull()
        ->and($event->workspace_id)->toBe($workspace->id);
});

it('links LLM tracking runs to monitored pages from the queued job', function (): void {
    [, , , $run] = geoObservationRun([
        'sources' => [
            ['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 80],
        ],
    ]);

    (new LinkLlmTrackingSourcesToPagesJob($run->id))->handle(app(PageGeoObservationBuilder::class));

    expect(PageGeoObservation::query()->where('llm_tracking_query_run_id', $run->id)->exists())->toBeTrue();
});

/**
 * @param array<string,mixed> $runOverrides
 * @return array{0:Workspace,1:ClientSite,2:LlmTrackingQuery,3:LlmTrackingQueryRun}
 */
function geoObservationRun(array $runOverrides = []): array
{
    config()->set('page_intelligence.safety.dns_overrides', [
        'argusly.com' => ['93.184.216.34'],
        'example-news.com' => ['93.184.216.34'],
        'competitor.acmeseo.com' => ['93.184.216.34'],
    ]);

    $organization = Organization::query()->create([
        'name' => 'GEO Observation Org',
        'slug' => 'geo-observation-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'GEO Observation Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Argusly',
        'site_url' => 'https://argusly.com',
        'allowed_domains' => ['argusly.com'],
        'is_active' => true,
    ]);

    $query = LlmTrackingQuery::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'GEO visibility',
        'query_text' => 'Best GEO visibility platform',
        'target_brand' => 'Argusly',
        'target_domain' => 'argusly.com',
        'brand_terms' => ['Argusly'],
        'competitor_terms' => ['AcmeSEO'],
        'target_urls' => ['https://argusly.com/features'],
        'locale' => 'en',
        'frequency' => 'daily',
        'priority' => 90,
        'is_active' => true,
    ]);

    $run = LlmTrackingQueryRun::query()->create(array_replace([
        'llm_tracking_query_id' => $query->id,
        'run_at' => Carbon::parse('2026-07-03 08:00:00'),
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'succeeded',
        'answer_text' => 'Argusly is cited via https://argusly.com/features and compared with AcmeSEO.',
        'normalized_response' => 'Argusly is cited via https://argusly.com/features and compared with AcmeSEO.',
        'parsed_payload' => ['response_text' => 'Argusly is cited via https://argusly.com/features and compared with AcmeSEO.'],
        'brand_hits' => [['term' => 'Argusly', 'count' => 1, 'bucket' => 'first']],
        'competitor_hits' => [['term' => 'AcmeSEO', 'count' => 1, 'bucket' => 'middle']],
        'detected_brands' => [['term' => 'Argusly', 'type' => 'brand', 'present' => true]],
        'detected_competitors' => [['term' => 'AcmeSEO', 'type' => 'competitor', 'present' => true]],
        'url_hits' => [['target_url' => 'https://argusly.com/features', 'count' => 1, 'bucket' => 'middle']],
        'citation_ranking' => ['brand' => ['bucket' => 'first'], 'url' => ['bucket' => 'middle']],
        'sources' => [['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 80]],
        'detected_domains' => ['argusly.com'],
        'brand_mentioned' => true,
        'urls_cited' => true,
        'competitors_mentioned' => true,
        'presence_score' => 1,
        'position_score' => 1,
        'citation_score' => 1,
        'context_score' => 0.7,
        'sentiment_score' => 0.7,
        'sentiment_label' => 'positive',
        'competitive_score' => 0.6,
        'competitor_pressure_score' => 0.4,
        'citation_diversity_score' => 0.5,
        'model_confidence_score' => 0.85,
        'ai_visibility_score' => 0.82,
        'visibility_breakdown' => ['ai_visibility_score' => 0.82],
    ], $runOverrides));

    return [$workspace, $site, $query, $run];
}
