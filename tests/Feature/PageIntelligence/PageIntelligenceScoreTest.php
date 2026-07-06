<?php

use App\Models\Campaign;
use App\Models\ClientSite;
use App\Models\MarketPackScoringModel;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\PageCampaignMatch;
use App\Models\PageCompetitorMatch;
use App\Models\PageContentExtraction;
use App\Models\PageEntity;
use App\Models\PageGeoObservation;
use App\Models\PageMarketPackMatch;
use App\Models\PagePrValue;
use App\Models\PageScore;
use App\Models\PageSentiment;
use App\Models\PageSerpObservation;
use App\Models\PageSnapshot;
use App\Models\PageTopic;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\PageIntelligence\MarketPacks\MarketPackInstaller;
use App\Services\PageIntelligence\PageIntelligenceScoreCalculator;
use Database\Seeders\MarketPackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calculates the Argusly Intelligence Score with full inputs', function (): void {
    [, $snapshot] = pageIntelligenceScoreScenario();

    $score = app(PageIntelligenceScoreCalculator::class)->calculate($snapshot);

    expect($score)->toBeInstanceOf(PageScore::class)
        ->and((float) $score->score)->toBeGreaterThan(60)
        ->and(data_get($score->breakdown_json, 'components.pr_value.available'))->toBeTrue()
        ->and(data_get($score->breakdown_json, 'components.geo_visibility.available'))->toBeTrue()
        ->and((float) data_get($score->metadata_json, 'confidence'))->toBe(100.0);
});

it('calculates with missing SERP and GEO inputs', function (): void {
    [, $snapshot] = pageIntelligenceScoreScenario(withVisibility: false);

    $score = app(PageIntelligenceScoreCalculator::class)->calculate($snapshot);

    expect((float) $score->score)->toBeGreaterThan(50)
        ->and(data_get($score->breakdown_json, 'components.serp_visibility.available'))->toBeFalse()
        ->and(data_get($score->breakdown_json, 'components.geo_visibility.available'))->toBeFalse()
        ->and(data_get($score->metadata_json, 'missing_inputs'))->toContain('serp_visibility', 'geo_visibility')
        ->and((float) data_get($score->metadata_json, 'confidence'))->toBeLessThan(100)
        ->and((float) data_get($score->metadata_json, 'missing_weight_total'))->toBeGreaterThan(0)
        ->and((float) data_get($score->metadata_json, 'raw_score'))->toBeGreaterThan((float) data_get($score->metadata_json, 'confidence_adjusted_score'));
});

it('keeps an old snapshot score stable after newer page-level inputs exist', function (): void {
    [$page, $oldSnapshot, $oldExtraction] = pageIntelligenceScoreScenario();
    $calculator = app(PageIntelligenceScoreCalculator::class);
    $before = $calculator->calculate($oldSnapshot->fresh());

    $newSnapshot = PageSnapshot::factory()->forPage($page)->create([
        'snapshot_number' => 2,
        'fetched_at' => now()->addDay(),
    ]);
    $newExtraction = PageContentExtraction::factory()->forSnapshot($newSnapshot)->create([
        'content_depth_score' => 10,
        'word_count' => 100,
    ]);
    pageIntelligenceNewerScoreInputs($page, $newSnapshot, $newExtraction);

    $after = $calculator->calculate($oldSnapshot->fresh());

    expect((float) $after->score)->toBe((float) $before->score)
        ->and(data_get($after->breakdown_json, 'components.pr_value.input_id'))->toBe(data_get($before->breakdown_json, 'components.pr_value.input_id'))
        ->and(data_get($after->breakdown_json, 'components.sentiment.input_id'))->toBe(data_get($before->breakdown_json, 'components.sentiment.input_id'))
        ->and(data_get($after->breakdown_json, 'components.serp_visibility.input_id'))->toBe(data_get($before->breakdown_json, 'components.serp_visibility.input_id'))
        ->and(data_get($after->breakdown_json, 'components.geo_visibility.input_id'))->toBe(data_get($before->breakdown_json, 'components.geo_visibility.input_id'))
        ->and(data_get($after->breakdown_json, 'components.topic_relevance.input_ids'))->toBe(data_get($before->breakdown_json, 'components.topic_relevance.input_ids'))
        ->and(data_get($after->breakdown_json, 'components.competitor_pressure.input_ids'))->toBe(data_get($before->breakdown_json, 'components.competitor_pressure.input_ids'))
        ->and(data_get($after->breakdown_json, 'components.pr_value.fallback_source'))->toBe('snapshot_scoped')
        ->and(data_get($after->breakdown_json, 'components.serp_visibility.fallback_source'))->toBe('snapshot_scoped');
});

it('allows latest snapshot scoring to use latest page-level values when no scoped value exists', function (): void {
    [$page, $snapshot] = pageIntelligenceScoreScenario(withVisibility: false);

    PageSerpObservation::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => null,
        'visibility_score' => 93,
        'observed_at' => ($snapshot->fetched_at ?: now())->copy()->addHour(),
    ]);
    PageGeoObservation::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => null,
        'geo_visibility_score' => 91,
        'observed_at' => ($snapshot->fetched_at ?: now())->copy()->addHour(),
    ]);

    $score = app(PageIntelligenceScoreCalculator::class)->calculate($snapshot->fresh());

    expect(data_get($score->breakdown_json, 'components.serp_visibility.fallback_source'))->toBe('latest_page_level_current_snapshot')
        ->and(data_get($score->breakdown_json, 'components.geo_visibility.fallback_source'))->toBe('latest_page_level_current_snapshot')
        ->and((float) data_get($score->breakdown_json, 'components.serp_visibility.score'))->toBe(93.0)
        ->and((float) data_get($score->breakdown_json, 'components.geo_visibility.score'))->toBe(91.0);
});

it('uses market pack weights to alter the score breakdown', function (): void {
    $this->seed(MarketPackSeeder::class);

    [$automotivePage, $automotiveSnapshot] = pageIntelligenceScoreScenario(marketPackKey: 'automotive');
    [$telecomPage, $telecomSnapshot] = pageIntelligenceScoreScenario(marketPackKey: 'telecom');

    app(MarketPackInstaller::class)->install(Workspace::query()->findOrFail($automotivePage->workspace_id), 'automotive');
    app(MarketPackInstaller::class)->install(Workspace::query()->findOrFail($telecomPage->workspace_id), 'telecom');

    $automotiveScore = app(PageIntelligenceScoreCalculator::class)->calculate($automotiveSnapshot);
    $telecomScore = app(PageIntelligenceScoreCalculator::class)->calculate($telecomSnapshot);

    expect(MarketPackScoringModel::query()->where('key', PageIntelligenceScoreCalculator::MODEL_KEY)->count())->toBeGreaterThanOrEqual(2)
        ->and(data_get($automotiveScore->breakdown_json, 'components.geo_visibility.weight'))
        ->not->toBe(data_get($telecomScore->breakdown_json, 'components.geo_visibility.weight'))
        ->and(data_get($automotiveScore->metadata_json, 'market_pack_key'))->toBe('automotive')
        ->and(data_get($telecomScore->metadata_json, 'market_pack_key'))->toBe('telecom');
});

it('stores an explainable score payload', function (): void {
    [, $snapshot] = pageIntelligenceScoreScenario(withVisibility: false);

    $score = app(PageIntelligenceScoreCalculator::class)->calculate($snapshot);

    expect($score->model_used)->toBe(PageIntelligenceScoreCalculator::MODEL_KEY)
        ->and($score->score_version)->toBe(PageIntelligenceScoreCalculator::MODEL_VERSION)
        ->and($score->computed_at)->not->toBeNull()
        ->and(data_get($score->metadata_json, 'model_key'))->toBe(PageIntelligenceScoreCalculator::MODEL_KEY)
        ->and(data_get($score->metadata_json, 'model_version'))->toBe(PageIntelligenceScoreCalculator::MODEL_VERSION)
        ->and(data_get($score->metadata_json, 'missing_inputs'))->toBeArray()
        ->and(data_get($score->metadata_json, 'confidence'))->not->toBeNull()
        ->and(data_get($score->breakdown_json, 'components'))->toBeArray();
});

function pageIntelligenceScoreScenario(?string $marketPackKey = null, bool $withVisibility = true): array
{
    $source = MonitoredSource::factory()->create([
        'authority_score' => 82,
        'trust_level' => 4,
        'metadata_json' => array_filter(['market_pack_key' => $marketPackKey]),
    ]);
    $siteHost = 'score-'.str()->random(8).'.example.test';
    $site = ClientSite::query()->create([
        'workspace_id' => $source->workspace_id,
        'name' => 'Score Site '.str()->random(6),
        'site_url' => 'https://'.$siteHost,
        'base_url' => 'https://'.$siteHost,
        'allowed_domains' => [$siteHost],
        'type' => ClientSite::TYPE_WORDPRESS,
        'is_active' => true,
    ]);
    $source->forceFill(['client_site_id' => $site->id])->save();
    $page = MonitoredPage::factory()->create([
        'organization_id' => $source->organization_id,
        'workspace_id' => $source->workspace_id,
        'client_site_id' => $site->id,
        'monitored_source_id' => $source->id,
        'domain' => $source->domain,
        'source_type' => $source->source_type,
        'published_at_current' => now()->subDays(3),
        'title_current' => 'Score Explainability Page',
    ]);
    $snapshot = PageSnapshot::factory()->forPage($page)->create();
    $extraction = PageContentExtraction::factory()->forSnapshot($snapshot)->create([
        'content_depth_score' => 74,
        'word_count' => 1200,
    ]);

    PagePrValue::query()->create(pageIntelligenceScoreAttributes($page, $snapshot, $extraction) + [
        'model_key' => 'argusly_pr_value',
        'model_version' => 'v1',
        'score' => 84,
        'estimated_value_amount' => 12000,
        'currency' => 'USD',
        'confidence' => 88,
        'breakdown_json' => ['factors' => ['source_authority' => ['score' => 82]]],
        'calculated_at' => now(),
    ]);
    PageSentiment::query()->create(pageIntelligenceScoreAttributes($page, $snapshot, $extraction) + [
        'target_type' => PageSentiment::TARGET_PAGE,
        'target_key' => 'page:'.$page->id,
        'target_name' => $page->title_current,
        'compound_score' => 0.42,
        'label' => 'positive',
        'confidence_score' => 0.9,
        'analysis_method' => 'test',
        'model_used' => 'test',
        'analyzer_version' => 'test',
        'explanation' => 'Positive business context.',
        'evidence_json' => [],
        'analyzed_at' => now(),
    ]);
    PageEntity::query()->create(pageIntelligenceScoreAttributes($page, $snapshot, $extraction) + [
        'entity_type' => PageEntity::TYPE_BRAND,
        'entity_key' => 'argusly',
        'entity_name' => 'Argusly',
        'source_type' => 'test',
        'mention_count' => 2,
        'first_position' => 20,
        'prominence_score' => 78,
        'confidence_score' => 90,
        'evidence_json' => [],
        'analysis_method' => 'test',
        'model_used' => 'test',
        'analyzer_version' => 'test',
        'observed_at' => now(),
    ]);
    PageEntity::query()->create(pageIntelligenceScoreAttributes($page, $snapshot, $extraction) + [
        'entity_type' => PageEntity::TYPE_COMPETITOR,
        'entity_key' => 'competitor',
        'entity_name' => 'Competitor',
        'source_type' => 'test',
        'mention_count' => 3,
        'first_position' => 80,
        'prominence_score' => 55,
        'confidence_score' => 80,
        'evidence_json' => [],
        'analysis_method' => 'test',
        'model_used' => 'test',
        'analyzer_version' => 'test',
        'observed_at' => now(),
    ]);
    PageTopic::query()->create(pageIntelligenceScoreAttributes($page, $snapshot, $extraction) + [
        'topic_key' => 'market-pack-topic',
        'topic_name' => 'Market pack topic',
        'topic_type' => 'market_pack_theme',
        'source_type' => 'market_pack',
        'mention_count' => 3,
        'first_position' => 40,
        'prominence_score' => 70,
        'confidence_score' => 76,
        'keywords_json' => ['market pack topic'],
        'evidence_json' => [],
        'classification_method' => 'test',
        'model_used' => 'test',
        'classifier_version' => 'test',
        'classified_at' => now(),
    ]);
    PageMarketPackMatch::query()->create(pageIntelligenceScoreAttributes($page, $snapshot, $extraction) + [
        'market_pack_key' => $marketPackKey ?: 'generic',
        'market_pack_name' => str($marketPackKey ?: 'generic')->headline(),
        'match_type' => 'theme',
        'match_score' => 88,
        'evidence_json' => [],
        'observed_at' => now(),
    ]);

    $competitor = SiteCompetitor::query()->create([
        'workspace_id' => $source->workspace_id,
        'client_site_id' => $site->id,
        'name' => 'Competitor',
        'domain' => 'competitor.example.test',
        'is_active' => true,
    ]);
    PageCompetitorMatch::query()->create(pageIntelligenceScoreAttributes($page, $snapshot, $extraction) + [
        'site_competitor_id' => $competitor->id,
        'match_type' => 'name',
        'match_score' => 82,
        'evidence_json' => [],
        'observed_at' => now(),
    ]);
    $campaign = Campaign::factory()->create([
        'organization_id' => $source->organization_id,
        'workspace_id' => $source->workspace_id,
        'client_site_id' => $site->id,
    ]);
    PageCampaignMatch::query()->create(pageIntelligenceScoreAttributes($page, $snapshot, $extraction) + [
        'campaign_id' => $campaign->id,
        'match_type' => 'campaign_keyword',
        'match_score' => 72,
        'evidence_json' => [],
        'observed_at' => now(),
    ]);

    if ($withVisibility) {
        PageSerpObservation::factory()->create([
            'organization_id' => $source->organization_id,
            'workspace_id' => $source->workspace_id,
            'client_site_id' => $site->id,
            'monitored_page_id' => $page->id,
            'page_snapshot_id' => $snapshot->id,
            'visibility_score' => 69,
        ]);
        PageGeoObservation::factory()->create([
            'organization_id' => $source->organization_id,
            'workspace_id' => $source->workspace_id,
            'client_site_id' => $site->id,
            'monitored_page_id' => $page->id,
            'page_snapshot_id' => $snapshot->id,
            'geo_visibility_score' => 86,
        ]);
    }

    return [$page, $snapshot, $extraction];
}

function pageIntelligenceNewerScoreInputs(MonitoredPage $page, PageSnapshot $snapshot, PageContentExtraction $extraction): void
{
    PagePrValue::query()->create(pageIntelligenceScoreAttributes($page, $snapshot, $extraction) + [
        'model_key' => 'argusly_pr_value',
        'model_version' => 'v1',
        'score' => 5,
        'estimated_value_amount' => 100,
        'currency' => 'USD',
        'confidence' => 40,
        'breakdown_json' => ['newer' => true],
        'calculated_at' => $snapshot->fetched_at,
    ]);
    PageSentiment::query()->create(pageIntelligenceScoreAttributes($page, $snapshot, $extraction) + [
        'target_type' => PageSentiment::TARGET_PAGE,
        'target_key' => 'page:'.$page->id,
        'target_name' => $page->title_current,
        'compound_score' => -0.9,
        'label' => 'negative',
        'confidence_score' => 0.95,
        'analysis_method' => 'test',
        'model_used' => 'test',
        'analyzer_version' => 'test',
        'explanation' => 'Newer negative context.',
        'evidence_json' => [],
        'analyzed_at' => $snapshot->fetched_at,
    ]);
    PageTopic::query()->create(pageIntelligenceScoreAttributes($page, $snapshot, $extraction) + [
        'topic_key' => 'newer-topic',
        'topic_name' => 'Newer topic',
        'topic_type' => 'market_pack_theme',
        'source_type' => 'market_pack',
        'mention_count' => 1,
        'first_position' => 10,
        'prominence_score' => 10,
        'confidence_score' => 9,
        'keywords_json' => ['newer topic'],
        'evidence_json' => [],
        'classification_method' => 'test',
        'model_used' => 'test',
        'classifier_version' => 'test',
        'classified_at' => $snapshot->fetched_at,
    ]);
    PageEntity::query()->create(pageIntelligenceScoreAttributes($page, $snapshot, $extraction) + [
        'entity_type' => PageEntity::TYPE_COMPETITOR,
        'entity_key' => 'newer-competitor',
        'entity_name' => 'Newer Competitor',
        'source_type' => 'test',
        'mention_count' => 1,
        'first_position' => 20,
        'prominence_score' => 9,
        'confidence_score' => 90,
        'evidence_json' => [],
        'analysis_method' => 'test',
        'model_used' => 'test',
        'analyzer_version' => 'test',
        'observed_at' => $snapshot->fetched_at,
    ]);
    $competitor = SiteCompetitor::query()->create([
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'name' => 'Newer Competitor',
        'domain' => 'newer-competitor.example.test',
        'is_active' => true,
    ]);
    PageCompetitorMatch::query()->create(pageIntelligenceScoreAttributes($page, $snapshot, $extraction) + [
        'site_competitor_id' => $competitor->id,
        'match_type' => 'name',
        'match_score' => 8,
        'evidence_json' => [],
        'observed_at' => $snapshot->fetched_at,
    ]);
    PageSerpObservation::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'visibility_score' => 4,
        'observed_at' => $snapshot->fetched_at,
    ]);
    PageGeoObservation::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'geo_visibility_score' => 3,
        'observed_at' => $snapshot->fetched_at,
    ]);
}

function pageIntelligenceScoreAttributes(MonitoredPage $page, PageSnapshot $snapshot, PageContentExtraction $extraction): array
{
    return [
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'page_content_extraction_id' => $extraction->id,
    ];
}
