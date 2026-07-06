<?php

use App\Jobs\PageIntelligence\AnalyzePageEntitiesJob;
use App\Jobs\PageIntelligence\AnalyzePageSentimentJob;
use App\Jobs\PageIntelligence\CalculatePagePrValueJob;
use App\Jobs\PageIntelligence\CalculateBasicPageScoresJob;
use App\Jobs\PageIntelligence\ClassifyPageTopicsJob;
use App\Jobs\PageIntelligence\EmitPageSignalsJob;
use App\Enums\SignalCategory;
use App\Enums\SignalType;
use App\Models\ClientSite;
use App\Models\CompanyIntelligenceProfile;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Organization;
use App\Models\PageContentExtraction;
use App\Models\PageEntity;
use App\Models\PageMention;
use App\Models\PagePrValue;
use App\Models\PageScore;
use App\Models\PageSentiment;
use App\Models\PageSnapshot;
use App\Models\PageTopic;
use App\Models\SignalEvent;
use App\Models\SignalFeedItem;
use App\Models\SignalMention;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\PageIntelligence\PageAnalysisService;
use App\Services\PageIntelligence\PagePrValueCalculator;
use App\Services\PageIntelligence\PageSignalEmitter;
use App\Services\PageIntelligence\PrValue\ArguslyPrValueModel;
use App\Services\PageIntelligence\PrValue\TraditionalAvePrValueModel;
use App\Services\PageIntelligence\PrValue\WeightedEarnedMediaValueModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates the page analysis schema', function (): void {
    foreach ([
        'page_entities',
        'page_mentions',
        'page_topics',
        'page_sentiments',
        'page_scores',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
        expect(Schema::hasColumn($table, 'id'))->toBeTrue();
        expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue();
    }

    expect(Schema::hasColumns('page_entities', [
        'workspace_id',
        'monitored_page_id',
        'page_snapshot_id',
        'entity_type',
        'entity_key',
        'entity_name',
        'prominence_score',
        'mention_count',
        'first_position',
        'confidence_score',
        'evidence_json',
        'model_used',
    ]))->toBeTrue();

    expect(Schema::hasColumns('page_sentiments', [
        'target_type',
        'target_key',
        'compound_score',
        'label',
        'confidence_score',
        'model_used',
        'explanation',
        'evidence_json',
    ]))->toBeTrue();

    expect(Schema::hasColumns('page_scores', [
        'score_type',
        'score',
        'score_version',
        'explanation',
        'breakdown_json',
        'evidence_json',
    ]))->toBeTrue();
});

it('detects brand mentions from company intelligence context', function (): void {
    $snapshot = pageAnalysisSnapshot(
        'Acme Robotics is a trusted robotics platform. Analysts say Acme Robotics improves warehouse operations.',
        ['company_name' => 'Acme Robotics']
    );

    (new AnalyzePageEntitiesJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));

    $entity = PageEntity::query()
        ->where('page_snapshot_id', $snapshot->id)
        ->where('entity_type', PageEntity::TYPE_BRAND)
        ->where('entity_key', 'acme_robotics')
        ->first();

    expect($entity)->not->toBeNull();
    expect($entity->mention_count)->toBe(2);
    expect($entity->first_position)->toBeGreaterThanOrEqual(0);
    expect((float) $entity->prominence_score)->toBeGreaterThan(0);
    expect((float) $entity->confidence_score)->toBeGreaterThan(70);
    expect($entity->evidence_json[0]['snippet'])->toContain('Acme Robotics');

    expect(PageMention::query()
        ->where('page_snapshot_id', $snapshot->id)
        ->where('entity_key', 'acme_robotics')
        ->count())->toBe(2);
});

it('detects competitor mentions from site competitor context', function (): void {
    [$snapshot, $site] = pageAnalysisSnapshotWithSite(
        'Rival Labs published a weak comparison page while Acme Robotics remained reliable.',
        ['company_name' => 'Acme Robotics']
    );

    SiteCompetitor::query()->create([
        'workspace_id' => $snapshot->workspace_id,
        'client_site_id' => $site->id,
        'name' => 'Rival Labs',
        'domain' => 'rival-labs.example',
        'is_active' => true,
    ]);

    (new AnalyzePageEntitiesJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));

    expect(PageEntity::query()
        ->where('page_snapshot_id', $snapshot->id)
        ->where('entity_type', PageEntity::TYPE_COMPETITOR)
        ->where('entity_key', 'rival_labs')
        ->exists())->toBeTrue();
});

it('classifies topics from market and company profile terms', function (): void {
    $snapshot = pageAnalysisSnapshot(
        'The article explains AI visibility, content intelligence and durable answer engine coverage.',
        [
            'company_name' => 'Acme Robotics',
            'primary_topics' => ['AI visibility', 'content intelligence'],
        ]
    );

    (new ClassifyPageTopicsJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));

    $topic = PageTopic::query()
        ->where('page_snapshot_id', $snapshot->id)
        ->where('topic_key', 'ai_visibility')
        ->first();

    expect($topic)->not->toBeNull();
    expect($topic->topic_name)->toBe('AI visibility');
    expect($topic->mention_count)->toBeGreaterThanOrEqual(1);
    expect($topic->model_used)->toBe('deterministic-topic-v1');
});

it('stores page level sentiment with provenance and explanation', function (): void {
    $snapshot = pageAnalysisSnapshot(
        'Acme Robotics has excellent growth, trusted support and reliable operations.',
        ['company_name' => 'Acme Robotics']
    );

    (new AnalyzePageSentimentJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));

    $sentiment = PageSentiment::query()
        ->where('page_snapshot_id', $snapshot->id)
        ->where('target_type', PageSentiment::TARGET_PAGE)
        ->first();

    expect($sentiment)->not->toBeNull();
    expect($sentiment->label)->toBe('positive');
    expect((float) $sentiment->compound_score)->toBeGreaterThan(0);
    expect($sentiment->model_used)->toBe('deterministic-sentiment-v1');
    expect($sentiment->explanation)->toContain('positive');
    expect($sentiment->evidence_json)->not->toBeEmpty();
});

it('stores entity level sentiment without flattening target context', function (): void {
    $snapshot = pageAnalysisSnapshot(
        'Acme Robotics is trusted and excellent. Rival Labs has weak delivery and risky support.',
        [
            'company_name' => 'Acme Robotics',
            'direct_competitors' => ['Rival Labs'],
        ]
    );

    (new AnalyzePageEntitiesJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new AnalyzePageSentimentJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));

    $brandSentiment = PageSentiment::query()
        ->where('page_snapshot_id', $snapshot->id)
        ->where('target_type', PageSentiment::TARGET_ENTITY)
        ->where('target_key', 'brand:acme_robotics')
        ->first();

    $competitorSentiment = PageSentiment::query()
        ->where('page_snapshot_id', $snapshot->id)
        ->where('target_type', PageSentiment::TARGET_COMPETITOR)
        ->where('target_key', 'rival_labs')
        ->first();

    expect($brandSentiment)->not->toBeNull();
    expect($brandSentiment->label)->toBe('positive');
    expect($brandSentiment->target_ref_type)->toBe(PageEntity::class);
    expect($competitorSentiment)->not->toBeNull();
    expect($competitorSentiment->label)->toBe('negative');
});

it('does not create duplicate mentions for the same snapshot on rerun', function (): void {
    $snapshot = pageAnalysisSnapshot(
        'Acme Robotics is trusted. Acme Robotics remains reliable.',
        ['company_name' => 'Acme Robotics']
    );

    $job = new AnalyzePageEntitiesJob((string) $snapshot->id);
    $service = app(PageAnalysisService::class);

    $job->handle($service);
    $firstMentionCount = PageMention::query()->where('page_snapshot_id', $snapshot->id)->count();

    $job->handle($service);

    expect(PageEntity::query()->where('page_snapshot_id', $snapshot->id)->count())->toBe(1);
    expect(PageMention::query()->where('page_snapshot_id', $snapshot->id)->count())->toBe($firstMentionCount);
});

it('calculates explainable versioned basic page scores', function (): void {
    $snapshot = pageAnalysisSnapshot(
        'Acme Robotics is trusted for AI visibility. Rival Labs is weak on reliable content intelligence.',
        [
            'company_name' => 'Acme Robotics',
            'direct_competitors' => ['Rival Labs'],
            'primary_topics' => ['AI visibility', 'content intelligence'],
        ]
    );

    (new AnalyzePageEntitiesJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new ClassifyPageTopicsJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new AnalyzePageSentimentJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new CalculateBasicPageScoresJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));

    $score = PageScore::query()
        ->where('page_snapshot_id', $snapshot->id)
        ->where('score_type', 'entity_coverage')
        ->first();

    expect($score)->not->toBeNull();
    expect((float) $score->score)->toBeGreaterThan(0);
    expect($score->score_version)->toBe('page-basic-score-v1');
    expect($score->model_used)->toBe('deterministic-score-v1');
    expect($score->breakdown_json)->toHaveKey('brandCount');
    expect($score->explanation)->not->toBeEmpty();
});

it('emits analyzed page mentions into signal intelligence without feed items', function (): void {
    $snapshot = pageAnalysisSnapshot(
        'Acme Robotics is trusted for AI visibility. Rival Labs is also mentioned in the market.',
        [
            'company_name' => 'Acme Robotics',
            'direct_competitors' => ['Rival Labs'],
            'primary_topics' => ['AI visibility'],
        ]
    );

    (new AnalyzePageEntitiesJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new ClassifyPageTopicsJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new EmitPageSignalsJob((string) $snapshot->id))->handle(app(PageSignalEmitter::class));

    $brandMention = SignalMention::query()
        ->where('workspace_id', $snapshot->workspace_id)
        ->where('mention_type', SignalMention::TYPE_BRAND)
        ->first();

    expect($brandMention)->not->toBeNull();
    expect($brandMention->source_type)->toBe('page_intelligence');
    expect($brandMention->source_ref_type)->toBe(PageMention::class);
    expect($brandMention->metadata['monitored_page_id'])->toBe($snapshot->monitored_page_id);
    expect($brandMention->metadata['page_snapshot_id'])->toBe($snapshot->id);
    expect(SignalFeedItem::query()->where('workspace_id', $snapshot->workspace_id)->count())->toBe(0);

    expect(SignalEvent::query()
        ->where('workspace_id', $snapshot->workspace_id)
        ->where('type', SignalType::BRAND_MENTIONED->value)
        ->exists())->toBeTrue();
});

it('emits negative brand page sentiment as a signal event', function (): void {
    $snapshot = pageAnalysisSnapshot(
        'Acme Robotics has weak support, poor reliability and risky delivery.',
        ['company_name' => 'Acme Robotics']
    );

    (new AnalyzePageEntitiesJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new AnalyzePageSentimentJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new EmitPageSignalsJob((string) $snapshot->id))->handle(app(PageSignalEmitter::class));

    $event = SignalEvent::query()
        ->where('workspace_id', $snapshot->workspace_id)
        ->where('category', SignalCategory::RISK->value)
        ->where('type', SignalType::NEGATIVE_SENTIMENT->value)
        ->first();

    expect($event)->not->toBeNull();
    expect($event->metadata['source'])->toBe('page_intelligence_sentiment');
    expect($event->metadata['page_snapshot_id'])->toBe($snapshot->id);
    expect($event->evidence[0]['type'])->toBe('page_sentiment');
    expect((float) $event->risk_score)->toBeGreaterThan(0);
});

it('avoids duplicate page signal events on repeated emission', function (): void {
    $snapshot = pageAnalysisSnapshot(
        'Acme Robotics is trusted for AI visibility.',
        [
            'company_name' => 'Acme Robotics',
            'primary_topics' => ['AI visibility'],
        ]
    );

    (new AnalyzePageEntitiesJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new ClassifyPageTopicsJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));

    $job = new EmitPageSignalsJob((string) $snapshot->id);
    $emitter = app(PageSignalEmitter::class);

    $job->handle($emitter);
    $eventCount = SignalEvent::query()->where('workspace_id', $snapshot->workspace_id)->count();
    $mentionCount = SignalMention::query()->where('workspace_id', $snapshot->workspace_id)->count();

    $job->handle($emitter);

    expect(SignalEvent::query()->where('workspace_id', $snapshot->workspace_id)->count())->toBe($eventCount);
    expect(SignalMention::query()->where('workspace_id', $snapshot->workspace_id)->count())->toBe($mentionCount);
});

it('stores explainable PR value records for each model', function (): void {
    $snapshot = pageAnalysisSnapshot(
        'Acme Robotics is trusted for AI visibility and content intelligence.',
        [
            'company_name' => 'Acme Robotics',
            'primary_topics' => ['AI visibility', 'content intelligence'],
        ]
    );
    pageAnalysisAttachSource($snapshot, reach: 25000);

    (new AnalyzePageEntitiesJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new ClassifyPageTopicsJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new AnalyzePageSentimentJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));

    foreach ([new TraditionalAvePrValueModel(), new WeightedEarnedMediaValueModel(), new ArguslyPrValueModel()] as $model) {
        $result = $model->calculate($snapshot->fresh());

        expect($result['score'])->toBeGreaterThan(0);
        expect($result['confidence'])->toBeGreaterThan(0);
        expect($result['currency'])->toBe('USD');
        expect($result['breakdown'])->toHaveKey('factors');
    }

    (new CalculatePagePrValueJob((string) $snapshot->id))->handle(app(PagePrValueCalculator::class));

    expect(PagePrValue::query()->where('page_snapshot_id', $snapshot->id)->count())->toBe(3);
    expect(PagePrValue::query()
        ->where('page_snapshot_id', $snapshot->id)
        ->where('model_key', 'argusly_pr_value')
        ->first()
        ?->breakdown_json)->toHaveKey('placeholders');
});

it('lowers Argusly PR Value for negative brand sentiment', function (): void {
    $positive = pageAnalysisSnapshot('Acme Robotics is trusted, reliable and excellent for AI visibility.', [
        'company_name' => 'Acme Robotics',
        'primary_topics' => ['AI visibility'],
    ]);
    $negative = pageAnalysisSnapshot('Acme Robotics has weak support, poor reliability and risky delivery for AI visibility.', [
        'company_name' => 'Acme Robotics',
        'primary_topics' => ['AI visibility'],
    ]);

    foreach ([$positive, $negative] as $snapshot) {
        pageAnalysisAttachSource($snapshot, reach: 10000);
        (new AnalyzePageEntitiesJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
        (new ClassifyPageTopicsJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
        (new AnalyzePageSentimentJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    }

    $model = new ArguslyPrValueModel();

    expect($model->calculate($negative->fresh())['score'])
        ->toBeLessThan($model->calculate($positive->fresh())['score']);
});

it('raises Argusly PR Value for high brand prominence', function (): void {
    $low = pageAnalysisSnapshot('The market discussed AI visibility. Acme Robotics appeared near the end.', [
        'company_name' => 'Acme Robotics',
        'primary_topics' => ['AI visibility'],
    ]);
    $high = pageAnalysisSnapshot('Acme Robotics leads the AI visibility market. Acme Robotics is trusted. Acme Robotics is reliable.', [
        'company_name' => 'Acme Robotics',
        'primary_topics' => ['AI visibility'],
    ]);

    foreach ([$low, $high] as $snapshot) {
        pageAnalysisAttachSource($snapshot, reach: 10000);
        (new AnalyzePageEntitiesJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
        (new ClassifyPageTopicsJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
        (new AnalyzePageSentimentJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    }

    $model = new ArguslyPrValueModel();

    expect($model->calculate($high->fresh())['score'])
        ->toBeGreaterThan($model->calculate($low->fresh())['score']);
});

it('calculates PR Value when estimated reach is missing', function (): void {
    $snapshot = pageAnalysisSnapshot('Acme Robotics is trusted for AI visibility.', [
        'company_name' => 'Acme Robotics',
        'primary_topics' => ['AI visibility'],
    ]);
    pageAnalysisAttachSource($snapshot, reach: null);

    (new AnalyzePageEntitiesJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new ClassifyPageTopicsJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new AnalyzePageSentimentJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));

    $result = (new ArguslyPrValueModel())->calculate($snapshot->fresh());

    expect($result['score'])->toBeGreaterThan(0);
    expect($result['estimated_value_amount'])->not->toBeNull();
    expect($result['breakdown']['factors']['estimated_reach']['raw'])->toBeNull();
});

it('updates PR Value rows for the same snapshot model and version on recalculation', function (): void {
    $snapshot = pageAnalysisSnapshot('Acme Robotics is trusted for AI visibility.', [
        'company_name' => 'Acme Robotics',
        'primary_topics' => ['AI visibility'],
    ]);
    pageAnalysisAttachSource($snapshot, reach: 5000);

    (new AnalyzePageEntitiesJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new ClassifyPageTopicsJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));
    (new AnalyzePageSentimentJob((string) $snapshot->id))->handle(app(PageAnalysisService::class));

    $job = new CalculatePagePrValueJob((string) $snapshot->id, ['argusly_pr_value']);
    $calculator = app(PagePrValueCalculator::class);
    $job->handle($calculator);

    $first = PagePrValue::query()->where('page_snapshot_id', $snapshot->id)->firstOrFail();

    pageAnalysisAttachSource($snapshot, authority: 95, trust: 9, reach: 75000);
    $job->handle($calculator);

    $updated = PagePrValue::query()->where('page_snapshot_id', $snapshot->id)->firstOrFail();

    expect(PagePrValue::query()->where('page_snapshot_id', $snapshot->id)->count())->toBe(1);
    expect($updated->id)->toBe($first->id);
    expect((float) $updated->estimated_value_amount)->toBeGreaterThan((float) $first->estimated_value_amount);
    expect($updated->metadata_json['calculation_policy'])->toBe('update_by_snapshot_model_version');
});

function pageAnalysisWorkspace(): Workspace
{
    $suffix = Str::lower(Str::random(8));
    $organization = Organization::query()->create([
        'name' => 'Page Analysis '.$suffix,
        'slug' => 'page-analysis-'.$suffix,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    return Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Page Analysis '.$suffix,
        'display_name' => 'Page Analysis '.$suffix,
    ]);
}

function pageAnalysisSnapshot(string $mainText, array $profileOverrides = []): PageSnapshot
{
    [$snapshot] = pageAnalysisSnapshotWithSite($mainText, $profileOverrides);

    return $snapshot;
}

/**
 * @return array{0: PageSnapshot, 1: ClientSite}
 */
function pageAnalysisSnapshotWithSite(string $mainText, array $profileOverrides = []): array
{
    $workspace = pageAnalysisWorkspace();
    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Primary site',
        'site_url' => 'https://acme.example',
        'allowed_domains' => ['acme.example'],
        'is_active' => true,
    ]);

    CompanyIntelligenceProfile::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'brand_key' => 'primary',
        'company_name' => 'Acme Robotics',
        'company_description' => 'Warehouse robotics platform.',
        'market_category' => 'B2B robotics',
        'primary_topics' => ['robotics operations'],
        'authority_areas' => [],
        'target_entities' => [],
        'strategic_keywords' => [],
        'direct_competitors' => [],
        'indirect_competitors' => [],
        'aspirational_competitors' => [],
        'source_type' => 'test',
        'is_default' => true,
        'status' => CompanyIntelligenceProfile::STATUS_ACTIVE,
    ], $profileOverrides));

    $url = 'https://news.example/'.Str::lower(Str::random(10));
    $page = MonitoredPage::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'canonical_url' => $url,
        'canonical_url_hash' => hash('sha256', $url),
        'first_seen_url' => $url,
        'first_seen_url_hash' => hash('sha256', $url),
        'final_url' => $url,
        'final_url_hash' => hash('sha256', $url),
        'domain' => 'news.example',
        'path' => parse_url($url, PHP_URL_PATH),
        'source_type' => 'manual',
        'page_type' => 'article',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'crawl_status' => MonitoredPage::CRAWL_STATUS_FETCHED,
        'metadata_json' => ['test' => true],
    ]);

    $snapshot = PageSnapshot::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'monitored_page_id' => $page->id,
        'snapshot_number' => 1,
        'requested_url' => $url,
        'final_url' => $url,
        'canonical_url' => $url,
        'http_status' => 200,
        'content_type' => 'text/html',
        'raw_html' => '<html><body><article>'.e($mainText).'</article></body></html>',
        'raw_html_hash' => hash('sha256', $mainText),
        'content_changed' => true,
        'fetched_at' => now(),
        'fetcher_version' => 'test',
    ]);

    PageContentExtraction::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'extraction_method' => 'test',
        'extractor_version' => 'test-v1',
        'title' => 'Analysis fixture',
        'language' => 'en',
        'main_text' => $mainText,
        'word_count' => str_word_count($mainText),
        'char_count' => strlen($mainText),
        'estimated_tokens' => (int) ceil(strlen($mainText) / 4),
        'quality_score' => 80,
        'metadata_json' => ['test' => true],
    ]);

    return [$snapshot, $site];
}

function pageAnalysisAttachSource(PageSnapshot $snapshot, int $authority = 82, int $trust = 8, ?int $reach = 18000): MonitoredSource
{
    $source = MonitoredSource::query()->create([
        'organization_id' => $snapshot->organization_id,
        'workspace_id' => $snapshot->workspace_id,
        'client_site_id' => $snapshot->client_site_id,
        'source_type' => 'news',
        'name' => 'Authority News',
        'base_url' => 'https://authority.example',
        'domain' => 'authority.example',
        'status' => MonitoredSource::STATUS_ACTIVE,
        'trust_level' => $trust,
        'authority_score' => $authority,
        'metadata_json' => array_filter([
            'estimated_reach' => $reach,
        ], fn ($value): bool => $value !== null),
    ]);

    $snapshot->page?->forceFill([
        'monitored_source_id' => $source->id,
        'indexability_status' => 'indexable',
        'published_at_current' => now(),
    ])->save();

    $snapshot->contentExtraction?->forceFill([
        'content_depth_score' => 78,
        'quality_score' => 82,
        'word_count' => max(1200, (int) $snapshot->contentExtraction?->word_count),
    ])->save();

    return $source;
}
