<?php

use App\Services\AgenticMarketing\Intelligence\MarketingEvidence;
use App\Services\AgenticMarketing\Intelligence\MarketingInsight;
use App\Services\AgenticMarketing\Intelligence\MarketingRecommendation;
use App\Models\MarketingObservation;
use App\Models\PageSnapshot;
use App\Services\PageIntelligence\Scoring\ScoreEvidence;
use App\Services\PerformanceIntelligence\PerformanceSignal;
use App\Support\Intelligence\CanonicalEntityReference;
use App\Support\Intelligence\CanonicalEntityType;
use App\Support\Intelligence\EntityReferenceNormalizer;
use App\Support\Intelligence\EntityReferenceResolver;
use App\Support\Intelligence\Evidence;
use App\Support\Intelligence\EvidenceBag;
use App\Support\Intelligence\EvidenceNormalizer;
use App\Support\Intelligence\EvidenceReference;
use App\Support\Intelligence\IntelligenceGraphEdge;
use App\Support\Intelligence\IntelligenceGraphEdgeType;
use App\Support\Intelligence\IntelligenceGraphNode;
use App\Support\Intelligence\IntelligenceGraphReference;
use App\Support\Intelligence\IntelligenceStage;
use App\Support\Intelligence\IntelligenceSignal;
use App\Support\Intelligence\IntelligenceSignalDirection;
use App\Support\Intelligence\IntelligenceSignalEvidence;
use App\Support\Intelligence\IntelligenceSignalSource;
use App\Support\Intelligence\IntelligenceSignalStrength;
use App\Support\Intelligence\IntelligenceSignalType;
use App\Support\Intelligence\Testing\FakeEvidenceCollector;
use App\Support\Intelligence\Testing\FakeEvidenceProjector;
use App\Support\Intelligence\Testing\FakeEntityReferenceMapper;
use App\Support\Intelligence\Testing\FakeIntelligenceGraphProjector;
use App\Support\Intelligence\Testing\FakeIntelligenceSignalProjector;
use App\Support\Intelligence\TimeWindow;
use App\Support\Intelligence\TimeWindowComparison;
use App\Support\Intelligence\TimeWindowPreset;
use App\Support\Intelligence\TimeWindowResolver;
use Carbon\CarbonImmutable;

it('normalizes canonical entity references without requiring a new entity table', function (): void {
    $reference = CanonicalEntityReference::fromName(
        CanonicalEntityType::TECHNOLOGY,
        '  AI Visibility Platform  ',
        aliases: ['Argusly', 'Argusly', 'Argusly AI']
    );

    expect($reference->type)->toBe(CanonicalEntityType::TECHNOLOGY->value)
        ->and($reference->name)->toBe('AI Visibility Platform')
        ->and($reference->key)->toBe('ai-visibility-platform')
        ->and($reference->aliases)->toBe(['Argusly', 'Argusly AI']);
});

it('normalizes entity reference payload values conservatively', function (): void {
    $normalizer = new EntityReferenceNormalizer();

    $brand = $normalizer->normalize(
        CanonicalEntityType::BRAND,
        ['name' => '  Argusly &amp; Co  ', 'aliases' => ['Argusly AI', 'argusly ai', 'Argusly & Co']]
    );
    $domain = $normalizer->normalize(CanonicalEntityType::DOMAIN, 'https://www.Example.com/products?utm_source=test');

    expect($brand)->toBeInstanceOf(CanonicalEntityReference::class)
        ->and($brand->type)->toBe('brand')
        ->and($brand->name)->toBe('Argusly & Co')
        ->and($brand->key)->toBe('argusly-co')
        ->and($brand->aliases)->toBe(['Argusly AI'])
        ->and($domain?->type)->toBe('domain')
        ->and($domain?->key)->toBe('example.com');
});

it('resolves payload fields through an opt-in mapper without changing stored payload shapes', function (): void {
    $canonical = CanonicalEntityReference::fromName(
        CanonicalEntityType::COMPANY,
        'OpenAI',
        aliases: ['Open AI']
    );
    $mapper = (new FakeEntityReferenceMapper())->mapName(CanonicalEntityType::BRAND, 'Open AI', $canonical);
    $resolver = new EntityReferenceResolver(new EntityReferenceNormalizer(), $mapper);

    $references = $resolver->resolvePayload([
        'brand_terms' => [' Open   AI ', 'Open AI'],
        'competitor_terms' => [
            ['name' => '  Example Competitor  ', 'aliases' => ['Example Co', 'example co']],
        ],
    ], [
        'brand_terms' => CanonicalEntityType::BRAND,
        'competitor_terms' => CanonicalEntityType::COMPETITOR,
    ], [
        'metadata' => ['scope' => 'llm_tracking'],
    ]);

    expect($references)->toHaveCount(2)
        ->and($references[0]->type)->toBe('company')
        ->and($references[0]->name)->toBe('OpenAI')
        ->and($references[0]->key)->toBe('openai')
        ->and($references[1]->type)->toBe('competitor')
        ->and($references[1]->name)->toBe('Example Competitor')
        ->and($references[1]->aliases)->toBe(['Example Co'])
        ->and($references[1]->metadata)->toMatchArray([
            'scope' => 'llm_tracking',
            'source_field' => 'competitor_terms',
        ]);
});

it('keeps phase two free of canonical entity database tables', function (): void {
    $migrationContents = collect(glob(base_path('database/migrations/*.php')) ?: [])
        ->map(fn (string $path): string => file_get_contents($path) ?: '')
        ->implode("\n");

    expect($migrationContents)->not->toContain("Schema::create('canonical_entities'")
        ->and($migrationContents)->not->toContain("Schema::create('canonical_entity_aliases'")
        ->and($migrationContents)->not->toContain("Schema::create('canonical_entity_links'");
});

it('merges evidence through the platform bag while preserving feature payloads', function (): void {
    $scoreEvidence = ScoreEvidence::merge(
        new ScoreEvidence(
            marketingObservationIds: ['obs-1', 'obs-1'],
            pageSnapshotIds: ['snapshot-1'],
            pageIntelligenceInputIds: ['page_topics' => ['topic-1']],
            sourceMetrics: ['score' => ['value' => 72]]
        ),
        new ScoreEvidence(
            marketingObservationIds: ['obs-2'],
            performanceSignalKeys: ['signal-1'],
            pageIntelligenceInputIds: ['page_topics' => ['topic-1', 'topic-2']],
            sourceMetrics: ['score' => ['confidence' => 0.81]]
        ),
    );

    $marketingEvidence = MarketingEvidence::fromEvidence(Evidence::merge(
        $scoreEvidence->toEvidence(),
        (new MarketingEvidence(pageScoreIds: ['score-1'], reportIds: ['report-1']))->toEvidence(),
    ));

    expect($scoreEvidence->marketingObservationIds)->toBe(['obs-1', 'obs-2'])
        ->and($scoreEvidence->pageIntelligenceInputIds['page_topics'])->toBe(['topic-1', 'topic-2'])
        ->and($scoreEvidence->sourceMetrics['score'])->toBe(['value' => 72, 'confidence' => 0.81])
        ->and($marketingEvidence->marketingObservationIds)->toBe(['obs-1', 'obs-2'])
        ->and($marketingEvidence->pageScoreIds)->toBe(['score-1'])
        ->and($marketingEvidence->reportIds)->toBe(['report-1'])
        ->and($marketingEvidence->performanceSignalKeys)->toBe(['signal-1']);
});

it('resolves rolling previous and year over year windows consistently', function (): void {
    $window = TimeWindow::between('2026-07-03 12:00:00', '2026-07-04 08:00:00');
    $previous = $window->previous();
    $yearAgo = $window->samePeriodPreviousYear();

    expect($window->start->toDateTimeString())->toBe('2026-07-03 00:00:00')
        ->and($window->end->toDateTimeString())->toBe('2026-07-04 23:59:59')
        ->and($window->periodsCount())->toBe(2)
        ->and($previous->start->toDateTimeString())->toBe('2026-07-01 00:00:00')
        ->and($previous->end->toDateTimeString())->toBe('2026-07-02 23:59:59')
        ->and($yearAgo->start->toDateString())->toBe('2025-07-03')
        ->and($window->contains('2026-07-04 12:00:00'))->toBeTrue()
        ->and(TimeWindow::rolling('2026-07-08', 3)->start->toDateString())->toBe('2026-07-06');
});

it('resolves named time window presets through the unified time engine', function (): void {
    $resolver = TimeWindowResolver::fixed(CarbonImmutable::parse('2026-07-09 10:15:00', 'Europe/Amsterdam'));

    $today = $resolver->resolve(TimeWindowPreset::TODAY, ['timezone' => 'Europe/Amsterdam']);
    $yesterday = $resolver->resolve('yesterday', ['timezone' => 'Europe/Amsterdam']);
    $last7Days = $resolver->resolve('last 7 days', ['timezone' => 'Europe/Amsterdam']);
    $last28Days = $resolver->resolve('last_28_days', ['timezone' => 'Europe/Amsterdam']);

    expect($today->start->toDateTimeString())->toBe('2026-07-09 00:00:00')
        ->and($today->end->toDateTimeString())->toBe('2026-07-09 23:59:59')
        ->and($yesterday->start->toDateString())->toBe('2026-07-08')
        ->and($last7Days->start->toDateString())->toBe('2026-07-03')
        ->and($last7Days->periodsCount())->toBe(7)
        ->and($last28Days->start->toDateString())->toBe('2026-06-12')
        ->and($last28Days->periodsCount())->toBe(28);
});

it('resolves rolling and custom ranges without changing the TimeWindow payload shape', function (): void {
    $resolver = TimeWindowResolver::fixed('2026-07-09 10:15:00');
    $rolling = $resolver->resolve('rolling', [
        'to' => '2026-07-09',
        'periods' => 4,
        'timezone' => 'Europe/Amsterdam',
    ]);
    $custom = $resolver->resolve([
        'preset' => 'custom_range',
        'period_start' => '2026-06-01 12:00:00',
        'period_end' => '2026-06-03 08:00:00',
        'timezone' => 'Europe/Amsterdam',
    ]);

    expect($rolling->start->toDateString())->toBe('2026-07-06')
        ->and($rolling->end->toDateString())->toBe('2026-07-09')
        ->and($custom->toArray())->toMatchArray([
            'period_start' => '2026-06-01 00:00:00',
            'period_end' => '2026-06-03 23:59:59',
            'granularity' => TimeWindow::GRANULARITY_DAILY,
            'periods_count' => 3,
        ]);
});

it('resolves previous-period and same-period-previous-year comparisons', function (): void {
    $resolver = new TimeWindowResolver();
    $previous = $resolver->resolveComparison('custom', [
        'from' => '2026-07-03',
        'to' => '2026-07-09',
        'comparison' => TimeWindowComparison::PREVIOUS_PERIOD,
    ]);
    $yearAgo = $resolver->resolveComparison('custom', [
        'from' => '2026-02-28',
        'to' => '2026-03-01',
        'comparison' => 'same period previous year',
    ]);

    expect($previous->type)->toBe(TimeWindowComparison::PREVIOUS_PERIOD)
        ->and($previous->comparison?->start->toDateString())->toBe('2026-06-26')
        ->and($previous->comparison?->end->toDateString())->toBe('2026-07-02')
        ->and($yearAgo->type)->toBe(TimeWindowComparison::SAME_PERIOD_PREVIOUS_YEAR)
        ->and($yearAgo->comparison?->start->toDateString())->toBe('2025-02-28')
        ->and($yearAgo->comparison?->end->toDateString())->toBe('2025-03-01');
});

it('resolves timezone-aware windows and stays safe across Amsterdam DST', function (): void {
    $resolver = TimeWindowResolver::fixed(CarbonImmutable::parse('2026-03-29 12:00:00', 'Europe/Amsterdam'));
    $window = $resolver->resolve('today', ['timezone' => 'Europe/Amsterdam']);

    expect($window->start->getTimezone()->getName())->toBe('Europe/Amsterdam')
        ->and($window->end->getTimezone()->getName())->toBe('Europe/Amsterdam')
        ->and($window->start->toDateTimeString())->toBe('2026-03-29 00:00:00')
        ->and($window->end->toDateTimeString())->toBe('2026-03-29 23:59:59')
        ->and($window->start->utcOffset())->toBe(60)
        ->and($window->end->utcOffset())->toBe(120)
        ->and($window->periodsCount())->toBe(1);
});

it('resolves campaign and release windows without storing time-window records', function (): void {
    $resolver = new TimeWindowResolver();
    $campaignWindow = $resolver->resolve('campaign window', [
        'campaign' => [
            'planned_start_date' => '2026-08-01',
            'planned_end_date' => '2026-08-15',
        ],
        'timezone' => 'Europe/Amsterdam',
    ]);
    $releaseWindow = $resolver->resolve('release_window', [
        'release_at' => '2026-09-10',
        'days_before' => 2,
        'days_after' => 3,
        'timezone' => 'Europe/Amsterdam',
    ]);

    expect($campaignWindow->start->toDateString())->toBe('2026-08-01')
        ->and($campaignWindow->end->toDateString())->toBe('2026-08-15')
        ->and($releaseWindow->start->toDateString())->toBe('2026-09-08')
        ->and($releaseWindow->end->toDateString())->toBe('2026-09-13');
});

it('keeps phase six free of time-window database tables', function (): void {
    $tableNames = collect(glob(base_path('database/migrations/*.php')) ?: [])
        ->flatMap(function (string $path): array {
            preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($path) ?: '', $matches);

            return $matches[1] ?? [];
        })
        ->values()
        ->all();

    expect($tableNames)->not->toContain('time_windows')
        ->and($tableNames)->not->toContain('time_window_presets')
        ->and($tableNames)->not->toContain('time_window_comparisons')
        ->and($tableNames)->not->toContain('time_periods');
});

it('exposes the formal intelligence pipeline stages on existing artifacts', function (): void {
    $start = CarbonImmutable::parse('2026-07-08 00:00:00');
    $end = CarbonImmutable::parse('2026-07-08 23:59:59');
    $evidence = new MarketingEvidence(marketingObservationIds: ['obs-1']);
    $signal = new PerformanceSignal(
        key: 'performance-intelligence:test',
        type: 'traffic_trend',
        subjectType: 'workspace',
        subjectKey: 'workspace',
        subjectName: 'Workspace',
        metricKey: 'sessions',
        direction: 'growth',
        confidence: 0.9,
        periodStart: $start,
        periodEnd: $end,
        sourceMetrics: [],
        observationIds: ['obs-1'],
        explanation: 'Sessions grew during the window.',
    );
    $insight = new MarketingInsight(
        key: 'insight:test',
        type: 'opportunity',
        title: 'Traffic is growing',
        summary: 'Traffic is growing with traceable observations.',
        direction: 'growth',
        severity: 72,
        confidence: 0.82,
        evidence: $evidence,
    );
    $recommendation = new MarketingRecommendation(
        key: 'recommendation:test',
        type: 'content_improvement',
        title: 'Refresh the page',
        summary: 'Improve the page while traffic is rising.',
        priority: 76,
        confidence: 0.8,
        evidence: $evidence,
    );

    expect(IntelligenceStage::RAW_OBSERVATION->precedes(IntelligenceStage::SIGNAL))->toBeTrue()
        ->and($signal->intelligenceStage())->toBe(IntelligenceStage::SIGNAL)
        ->and($insight->intelligenceStage())->toBe(IntelligenceStage::INSIGHT)
        ->and($recommendation->intelligenceStage())->toBe(IntelligenceStage::RECOMMENDATION)
        ->and($signal->toArray()['intelligence_stage'])->toBe('signal')
        ->and($insight->toArray()['intelligence_stage'])->toBe('insight')
        ->and($recommendation->toArray()['intelligence_stage'])->toBe('recommendation');
});

it('creates intelligence graph nodes from canonical entity references', function (): void {
    $node = IntelligenceGraphNode::entity(
        CanonicalEntityReference::fromName(CanonicalEntityType::BRAND, 'Argusly', metadata: [
            'scope' => 'architecture_test',
        ]),
        stage: IntelligenceStage::INSIGHT,
        metadata: [
            'owner' => 'growth',
            'api_key' => 'secret-key',
        ],
    );

    $payload = $node->toArray();

    expect($node->key())->toBe('entity:brand:argusly')
        ->and($payload['type'])->toBe('entity')
        ->and($payload['label'])->toBe('Argusly')
        ->and($payload['intelligence_stage'])->toBe('insight')
        ->and(data_get($payload, 'reference.entity.type'))->toBe('brand')
        ->and(data_get($payload, 'reference.entity.key'))->toBe('argusly')
        ->and($payload['metadata']['owner'])->toBe('growth')
        ->and($payload['metadata']['api_key'])->toBe('[redacted]');
});

it('creates intelligence graph edges without changing source or target payloads', function (): void {
    $source = IntelligenceGraphReference::page('/pricing', 'Pricing page');
    $target = IntelligenceGraphReference::topic('ai-visibility', 'AI visibility');
    $edge = new IntelligenceGraphEdge(
        IntelligenceGraphEdgeType::MENTIONS,
        $source,
        $target,
        confidence: 0.78,
        metadata: ['match' => 'heading'],
        provenance: ['projector' => 'unit-test'],
        stage: IntelligenceStage::RAW_OBSERVATION,
    );

    $payload = $edge->toArray();

    expect($edge->type)->toBe('mentions')
        ->and($payload['source_key'])->toBe('page:/pricing')
        ->and($payload['target_key'])->toBe('topic:ai-visibility')
        ->and($payload['confidence'])->toBe(0.78)
        ->and($payload['metadata'])->toBe(['match' => 'heading'])
        ->and($payload['provenance'])->toBe(['projector' => 'unit-test'])
        ->and($payload['intelligence_stage'])->toBe('raw_observation');
});

it('describes entity page observation and reference edges in memory', function (): void {
    $entity = IntelligenceGraphReference::entity('Argusly', CanonicalEntityType::BRAND);
    $page = IntelligenceGraphReference::page('/blog/ai-visibility', 'AI visibility article');
    $observation = IntelligenceGraphReference::observation('marketing_observation:obs-1', 'Sessions grew');
    $reference = IntelligenceGraphReference::reference('source:gsc:query:ai-visibility', 'Search Console query');

    $edges = [
        new IntelligenceGraphEdge(IntelligenceGraphEdgeType::REFERENCES, $page, $entity),
        new IntelligenceGraphEdge(IntelligenceGraphEdgeType::EVIDENCES, $observation, $page),
        new IntelligenceGraphEdge(IntelligenceGraphEdgeType::DERIVES_FROM, $observation, $reference),
    ];

    expect(collect($edges)->map(fn (IntelligenceGraphEdge $edge): string => $edge->source->graphKey().' -> '.$edge->target->graphKey())->all())
        ->toBe([
            'page:/blog/ai-visibility -> entity:brand:argusly',
            'observation:marketing_observation:obs-1 -> page:/blog/ai-visibility',
            'observation:marketing_observation:obs-1 -> reference:source:gsc:query:ai-visibility',
        ]);
});

it('attaches evidence to intelligence graph edges through the support evidence bag', function (): void {
    $edge = new IntelligenceGraphEdge(
        IntelligenceGraphEdgeType::EVIDENCES,
        IntelligenceGraphReference::observation('obs-1'),
        IntelligenceGraphReference::signal('performance-intelligence:sessions-growth'),
        evidence: new Evidence(
            references: [
                'marketing_observation_ids' => ['obs-1', 'obs-1'],
                'page_intelligence_input_ids' => ['page_topics' => ['topic-1']],
            ],
            sourceMetrics: ['sessions' => ['delta' => 42]]
        ),
    );

    expect($edge->evidence->referenceIds('marketing_observation_ids'))->toBe(['obs-1'])
        ->and($edge->toArray()['evidence']['page_intelligence_input_ids'])->toBe(['page_topics' => ['topic-1']])
        ->and($edge->toArray()['evidence']['source_metrics'])->toBe(['sessions' => ['delta' => 42]]);
});

it('attaches time windows to intelligence graph edges without persistence', function (): void {
    $window = TimeWindow::between('2026-07-01 08:00:00', '2026-07-07 10:00:00');
    $edge = (new IntelligenceGraphEdge(
        IntelligenceGraphEdgeType::MEASURES,
        IntelligenceGraphReference::objective('objective:traffic-growth', 'Traffic growth'),
        IntelligenceGraphReference::signal('sessions-growth', 'Sessions growth'),
    ))->within($window);

    expect($edge->timeWindow)->toBe($window)
        ->and($edge->toArray()['time_window'])->toMatchArray([
            'period_start' => '2026-07-01 00:00:00',
            'period_end' => '2026-07-07 23:59:59',
            'granularity' => TimeWindow::GRANULARITY_DAILY,
            'periods_count' => 7,
        ]);
});

it('redacts secrets from graph metadata and provenance', function (): void {
    $edge = new IntelligenceGraphEdge(
        IntelligenceGraphEdgeType::RELATES_TO,
        IntelligenceGraphReference::page('/secure', metadata: [
            'access_token' => 'page-token',
        ]),
        IntelligenceGraphReference::reference('external:event'),
        metadata: [
            'nested' => ['refresh_token' => 'secret-refresh-token'],
            'visible' => 'kept',
        ],
        provenance: [
            'authorization' => 'Bearer secret',
            'source' => 'unit-test',
        ],
    );

    $payload = $edge->toArray();

    expect(data_get($payload, 'source.metadata.access_token'))->toBe('[redacted]')
        ->and(data_get($payload, 'metadata.nested.refresh_token'))->toBe('[redacted]')
        ->and(data_get($payload, 'metadata.visible'))->toBe('kept')
        ->and(data_get($payload, 'provenance.authorization'))->toBe('[redacted]')
        ->and(data_get($payload, 'provenance.source'))->toBe('unit-test');
});

it('projects an in-memory intelligence graph through the fake projector', function (): void {
    $entityNode = IntelligenceGraphNode::entity('Argusly', CanonicalEntityType::BRAND);
    $page = IntelligenceGraphReference::page('/pricing', 'Pricing page');
    $edge = new IntelligenceGraphEdge(
        IntelligenceGraphEdgeType::SUPPORTS,
        $page,
        $entityNode,
        confidence: 0.91,
        evidence: new Evidence(['page_snapshot_ids' => ['snapshot-1']])
    );

    $graph = (new FakeIntelligenceGraphProjector())
        ->project([$entityNode, $edge])
        ->graph();

    expect($graph['nodes'])->toHaveCount(2)
        ->and(collect($graph['nodes'])->pluck('key')->all())->toContain('entity:brand:argusly', 'page:/pricing')
        ->and($graph['edges'])->toHaveCount(1)
        ->and($graph['edges'][0]['type'])->toBe('supports')
        ->and($graph['edges'][0]['evidence']['page_snapshot_ids'])->toBe(['snapshot-1']);
});

it('keeps phase three free of intelligence graph database tables', function (): void {
    $tableNames = collect(glob(base_path('database/migrations/*.php')) ?: [])
        ->flatMap(function (string $path): array {
            preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($path) ?: '', $matches);

            return $matches[1] ?? [];
        })
        ->values()
        ->all();

    expect($tableNames)->not->toContain('intelligence_graph_nodes')
        ->and($tableNames)->not->toContain('intelligence_graph_edges')
        ->and($tableNames)->not->toContain('graph_nodes')
        ->and($tableNames)->not->toContain('graph_edges')
        ->and($tableNames)->not->toContain('canonical_entities')
        ->and($tableNames)->not->toContain('canonical_entity_aliases')
        ->and($tableNames)->not->toContain('canonical_entity_links');
});

it('creates intelligence signals over canonical subjects without persistence', function (): void {
    $signal = new IntelligenceSignal(
        type: IntelligenceSignalType::TRAFFIC_TREND,
        subject: CanonicalEntityReference::fromName(CanonicalEntityType::BRAND, 'Argusly'),
        metric: 'sessions',
        value: 150,
        baseline: 100,
        confidence: 0.86,
        timeWindow: TimeWindow::between('2026-07-01', '2026-07-07'),
        source: new IntelligenceSignalSource(
            provider: 'Google Analytics 4',
            dataset: 'traffic',
            key: 'ga4:sessions'
        ),
        key: 'signal:sessions-growth',
    );

    $payload = $signal->toArray();

    expect($signal->intelligenceStage())->toBe(IntelligenceStage::SIGNAL)
        ->and($payload['key'])->toBe('signal:sessions-growth')
        ->and($payload['type'])->toBe('traffic_trend')
        ->and($payload['subject_key'])->toBe('entity:brand:argusly')
        ->and($payload['metric_key'])->toBe('sessions')
        ->and($payload['value'])->toBe(150.0)
        ->and($payload['baseline'])->toBe(100.0)
        ->and($payload['delta'])->toBe(50.0)
        ->and($payload['direction'])->toBe('growth')
        ->and($payload['strength'])->toBe('strong')
        ->and($payload['confidence'])->toBe(0.86)
        ->and(data_get($payload, 'source.provider'))->toBe('google_analytics_4')
        ->and(data_get($payload, 'time_window.periods_count'))->toBe(7);
});

it('classifies growth signals from positive deltas', function (): void {
    $signal = new IntelligenceSignal(
        type: IntelligenceSignalType::ORGANIC_GROWTH,
        subject: IntelligenceGraphReference::page('/blog/ai-visibility', 'AI visibility article'),
        metric: 'clicks',
        value: 180,
        baseline: 100,
        confidence: 0.74,
    );

    expect($signal->direction)->toBe(IntelligenceSignalDirection::GROWTH)
        ->and($signal->strength)->toBe(IntelligenceSignalStrength::MODERATE)
        ->and($signal->delta)->toBe(80.0);
});

it('classifies decline signals from negative deltas', function (): void {
    $signal = new IntelligenceSignal(
        type: IntelligenceSignalType::PERFORMANCE_RISK,
        subject: IntelligenceGraphReference::topic('ai-visibility', 'AI visibility'),
        metric: 'engagement_rate',
        value: 0.18,
        baseline: 0.24,
        confidence: 0.42,
    );

    expect($signal->direction)->toBe(IntelligenceSignalDirection::DECLINE)
        ->and($signal->strength)->toBe(IntelligenceSignalStrength::WEAK)
        ->and($signal->delta)->toBe(-0.06);
});

it('classifies neutral signals from unchanged measurements', function (): void {
    $signal = new IntelligenceSignal(
        type: IntelligenceSignalType::CONTENT_MOMENTUM,
        subject: IntelligenceGraphReference::page('/pricing', 'Pricing page'),
        metric: 'conversions',
        value: 12,
        baseline: 12,
        confidence: 0.62,
    );

    expect($signal->direction)->toBe(IntelligenceSignalDirection::NEUTRAL)
        ->and($signal->strength)->toBe(IntelligenceSignalStrength::MODERATE)
        ->and($signal->delta)->toBe(0.0);
});

it('creates insufficient data signals explicitly', function (): void {
    $signal = IntelligenceSignal::insufficientData(
        type: IntelligenceSignalType::INSUFFICIENT_DATA,
        subject: IntelligenceGraphReference::page('/pricing', 'Pricing page'),
        metric: 'assisted_conversions',
        key: 'signal:insufficient-assisted-conversions',
    );

    expect($signal->direction)->toBe(IntelligenceSignalDirection::INSUFFICIENT_DATA)
        ->and($signal->strength)->toBe(IntelligenceSignalStrength::INSUFFICIENT)
        ->and($signal->confidence)->toBe(0.0)
        ->and($signal->toArray()['value'])->toBeNull()
        ->and($signal->toArray()['baseline'])->toBeNull()
        ->and($signal->toArray()['delta'])->toBeNull();
});

it('classifies confidence and strength for provider-agnostic scores', function (): void {
    $signal = new IntelligenceSignal(
        type: 'custom provider signal',
        subject: IntelligenceGraphReference::topic('agentic-marketing', 'Agentic marketing'),
        metric: 'share_of_voice',
        value: 92,
        baseline: 80,
        confidence: 92,
    );

    expect(IntelligenceSignalStrength::fromConfidence(0.1, IntelligenceSignalDirection::GROWTH))->toBe(IntelligenceSignalStrength::INSUFFICIENT)
        ->and(IntelligenceSignalStrength::fromConfidence(0.4, IntelligenceSignalDirection::GROWTH))->toBe(IntelligenceSignalStrength::WEAK)
        ->and(IntelligenceSignalStrength::fromConfidence(0.6, IntelligenceSignalDirection::GROWTH))->toBe(IntelligenceSignalStrength::MODERATE)
        ->and(IntelligenceSignalStrength::fromConfidence(0.9, IntelligenceSignalDirection::GROWTH))->toBe(IntelligenceSignalStrength::STRONG)
        ->and($signal->confidence)->toBe(0.92)
        ->and($signal->strength)->toBe(IntelligenceSignalStrength::STRONG)
        ->and($signal->type)->toBe('custom_provider_signal');
});

it('attaches evidence to intelligence signals through the support evidence bag', function (): void {
    $signalEvidence = new IntelligenceSignalEvidence(
        evidence: new Evidence(
            references: [
                'marketing_observation_ids' => ['obs-1', 'obs-1'],
                'performance_signal_keys' => ['legacy-signal-1'],
            ],
            sourceMetrics: [
                'sessions' => ['current' => 150, 'previous' => 100],
            ]
        ),
        graphReferences: [
            IntelligenceGraphReference::observation('obs-1', 'Sessions grew'),
        ],
    );

    $signal = new IntelligenceSignal(
        type: IntelligenceSignalType::TRAFFIC_TREND,
        subject: IntelligenceGraphReference::page('/blog/ai-visibility'),
        metric: 'sessions',
        value: 150,
        baseline: 100,
        confidence: 0.81,
        evidence: $signalEvidence,
    );

    expect($signal->evidence->referenceIds('marketing_observation_ids'))->toBe(['obs-1'])
        ->and($signal->toArray()['evidence']['performance_signal_keys'])->toBe(['legacy-signal-1'])
        ->and($signal->toArray()['evidence']['source_metrics']['sessions'])->toBe(['current' => 150, 'previous' => 100])
        ->and($signal->graphReferences[0]->graphKey())->toBe('observation:obs-1');
});

it('attaches graph references and emits signal graph edges in memory', function (): void {
    $signal = new IntelligenceSignal(
        type: IntelligenceSignalType::VISIBILITY_TREND,
        subject: IntelligenceGraphReference::entity('Argusly', CanonicalEntityType::BRAND),
        metric: 'ai_citations',
        value: 14,
        baseline: 9,
        confidence: 0.77,
        graphReferences: [
            IntelligenceGraphReference::observation('obs-visibility-1'),
            IntelligenceGraphReference::reference('source:gsc:query:argusly'),
            IntelligenceGraphReference::topic('ai-visibility'),
        ],
        key: 'signal:visibility-growth',
    );

    $edges = $signal->toGraphEdges();

    expect($signal->toArray()['graph_references'])->toHaveCount(3)
        ->and(collect($edges)->map(fn (IntelligenceGraphEdge $edge): string => $edge->type)->all())->toBe([
            'measures',
            'evidences',
            'derives_from',
            'references',
        ])
        ->and($edges[0]->source->graphKey())->toBe('entity:brand:argusly')
        ->and($edges[0]->target->graphKey())->toBe('signal:signal:visibility-growth');
});

it('redacts secrets from signal metadata provenance sources and graph references', function (): void {
    $signal = new IntelligenceSignal(
        type: IntelligenceSignalType::DATA_QUALITY,
        subject: IntelligenceGraphReference::reference('dataset:private', metadata: [
            'access_token' => 'subject-token',
        ]),
        metric: 'row_coverage',
        value: 0.72,
        baseline: 0.9,
        confidence: 0.66,
        source: new IntelligenceSignalSource(
            provider: 'warehouse',
            metadata: ['api_key' => 'source-secret']
        ),
        metadata: [
            'visible' => 'kept',
            'nested' => ['refresh_token' => 'metadata-secret'],
        ],
        provenance: [
            'authorization' => 'Bearer secret',
            'projector' => 'unit-test',
        ],
    );

    $payload = $signal->toArray();

    expect(data_get($payload, 'subject.metadata.access_token'))->toBe('[redacted]')
        ->and(data_get($payload, 'source.metadata.api_key'))->toBe('[redacted]')
        ->and(data_get($payload, 'metadata.visible'))->toBe('kept')
        ->and(data_get($payload, 'metadata.nested.refresh_token'))->toBe('[redacted]')
        ->and(data_get($payload, 'provenance.authorization'))->toBe('[redacted]')
        ->and(data_get($payload, 'provenance.projector'))->toBe('unit-test');
});

it('projects in-memory intelligence signals through the fake signal projector', function (): void {
    $signal = new IntelligenceSignal(
        type: IntelligenceSignalType::TRAFFIC_TREND,
        subject: IntelligenceGraphReference::page('/pricing', 'Pricing page'),
        metric: 'sessions',
        value: 220,
        baseline: 160,
        confidence: 0.88,
        evidence: new Evidence(['marketing_observation_ids' => ['obs-1']]),
        graphReferences: [
            IntelligenceGraphReference::observation('obs-1'),
        ],
        key: 'signal:pricing-sessions-growth',
    );

    $projector = (new FakeIntelligenceSignalProjector())->project([$signal]);
    $graph = $projector->graph();

    expect($projector->signals())->toHaveCount(1)
        ->and($projector->signals()[0]['key'])->toBe('signal:pricing-sessions-growth')
        ->and($graph['nodes'])->toHaveCount(3)
        ->and(collect($graph['nodes'])->pluck('key')->all())->toContain(
            'page:/pricing',
            'signal:signal:pricing-sessions-growth',
            'observation:obs-1'
        )
        ->and($graph['edges'])->toHaveCount(2)
        ->and(collect($graph['edges'])->pluck('type')->all())->toBe(['measures', 'evidences']);
});

it('keeps phase four free of new generic signal engine database tables', function (): void {
    $migrationPaths = collect(glob(base_path('database/migrations/*.php')) ?: []);
    $tableNames = $migrationPaths
        ->flatMap(function (string $path): array {
            preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($path) ?: '', $matches);

            return $matches[1] ?? [];
        })
        ->values()
        ->all();

    expect($tableNames)->not->toContain('intelligence_signals')
        ->and($tableNames)->not->toContain('intelligence_signal_sources')
        ->and($tableNames)->not->toContain('intelligence_signal_evidence')
        ->and($tableNames)->not->toContain('intelligence_signal_graph_edges')
        ->and($tableNames)->not->toContain('signal_engine_signals')
        ->and($tableNames)->not->toContain('signal_engine_edges')
        ->and($migrationPaths->filter(fn (string $path): bool => str_contains(basename($path), 'intelligence_signal') || str_contains(basename($path), 'signal_engine'))->all())->toBe([]);
});

it('creates normalized evidence references with graph and time context', function (): void {
    $window = TimeWindow::between('2026-07-01', '2026-07-07');
    $reference = EvidenceReference::pageSnapshot(
        'snapshot-1',
        'Pricing page snapshot',
        confidence: 91,
        weight: 2.5,
        reason: 'Latest scored crawl',
        timeWindow: $window,
        metadata: ['access_token' => 'secret-token', 'visible' => 'kept'],
        provenance: ['collector' => 'unit-test'],
    );

    $payload = $reference->toArray();

    expect($reference->type)->toBe(EvidenceReference::TYPE_PAGE_SNAPSHOT)
        ->and($reference->confidence)->toBe(0.91)
        ->and($reference->weight)->toBe(2.5)
        ->and($reference->toGraphReference()->graphKey())->toBe('page_snapshot:snapshot-1')
        ->and(data_get($payload, 'time_window.periods_count'))->toBe(7)
        ->and(data_get($payload, 'metadata.access_token'))->toBe('[redacted]')
        ->and(data_get($payload, 'metadata.visible'))->toBe('kept');
});

it('aggregates normalized evidence bags while retaining legacy evidence keys', function (): void {
    $first = new EvidenceBag([
        EvidenceReference::marketingObservation('obs-1', confidence: 0.42, weight: 1.0, metadata: ['channel' => 'organic']),
        EvidenceReference::pageSnapshot('snapshot-1'),
    ], [
        'sessions' => ['current' => 120],
    ]);
    $second = new EvidenceBag([
        EvidenceReference::marketingObservation('obs-1', confidence: 0.84, weight: 2.0, metadata: ['device' => 'desktop']),
        EvidenceReference::performanceSignal('signal-1'),
    ], [
        'sessions' => ['previous' => 80],
    ]);

    $bag = EvidenceBag::merge($first, $second);
    $observation = $bag->referencesFor(EvidenceReference::TYPE_MARKETING_OBSERVATION)[0];
    $legacy = $bag->toEvidence()->toArray();

    expect($bag->references)->toHaveCount(3)
        ->and($bag->referenceKeys(EvidenceReference::TYPE_MARKETING_OBSERVATION))->toBe(['obs-1'])
        ->and($observation->confidence)->toBe(0.84)
        ->and($observation->weight)->toBe(3.0)
        ->and($observation->metadata)->toMatchArray(['channel' => 'organic', 'device' => 'desktop'])
        ->and($legacy['marketing_observation_ids'])->toBe(['obs-1'])
        ->and($legacy['page_snapshot_ids'])->toBe(['snapshot-1'])
        ->and($legacy['performance_signal_keys'])->toBe(['signal-1'])
        ->and($legacy['source_metrics']['sessions'])->toBe(['current' => 120, 'previous' => 80]);
});

it('keeps score evidence payload compatibility through the normalizer', function (): void {
    $scoreEvidence = new ScoreEvidence(
        marketingObservationIds: ['obs-1'],
        pageSnapshotIds: ['snapshot-1'],
        trendIds: ['trend-1'],
        performanceSignalKeys: ['signal-1'],
        pageIntelligenceInputIds: ['page_topics' => ['topic-1']],
        sourceMetrics: ['score' => ['value' => 72, 'confidence' => 0.81]]
    );
    $before = $scoreEvidence->toArray();

    $bag = (new EvidenceNormalizer())->normalize($scoreEvidence);
    $roundTrip = ScoreEvidence::fromEvidence($bag->toEvidence());

    expect($scoreEvidence->toArray())->toBe($before)
        ->and($roundTrip->toArray())->toBe($before)
        ->and($bag->referenceKeys(EvidenceReference::TYPE_MARKETING_OBSERVATION))->toBe(['obs-1'])
        ->and($bag->referenceKeys(EvidenceReference::TYPE_PAGE_SNAPSHOT))->toBe(['snapshot-1']);
});

it('keeps marketing evidence payload compatibility through the normalizer', function (): void {
    $marketingEvidence = new MarketingEvidence(
        marketingObservationIds: ['obs-1'],
        pageSnapshotIds: ['snapshot-1'],
        pageScoreIds: ['score-1'],
        trendIds: ['trend-1'],
        performanceSignalKeys: ['signal-1'],
        pageIntelligenceInputIds: ['page_topics' => ['topic-1']],
        reportIds: ['report-1'],
        scheduledBriefingIds: ['briefing-1'],
        sourceMetrics: ['revenue' => ['delta' => 14]]
    );
    $before = $marketingEvidence->toArray();

    $bag = (new EvidenceNormalizer())->normalize($marketingEvidence);
    $roundTrip = MarketingEvidence::fromEvidence($bag->toEvidence());

    expect($marketingEvidence->toArray())->toBe($before)
        ->and($roundTrip->toArray())->toBe($before)
        ->and($bag->referenceKeys(EvidenceReference::TYPE_REPORT))->toBe(['report-1'])
        ->and($bag->referenceKeys(EvidenceReference::TYPE_BRIEFING))->toBe(['briefing-1']);
});

it('normalizes connector observations into evidence without writing payloads', function (): void {
    $observation = new MarketingObservation();
    $observation->forceFill([
        'id' => 'obs-1',
        'metric_key' => 'sessions',
        'metric_value' => 120,
        'unit' => 'count',
        'period_start' => CarbonImmutable::parse('2026-07-01 10:00:00'),
        'period_end' => CarbonImmutable::parse('2026-07-01 10:30:00'),
        'granularity' => MarketingObservation::GRANULARITY_DAILY,
        'confidence_score' => 0.82,
        'quality_score' => 0.74,
        'connector_sync_run_id' => 'sync-1',
        'source_metadata_json' => ['api_key' => 'secret', 'provider' => 'ga4'],
    ]);

    $bag = (new EvidenceNormalizer())->normalize($observation);
    $reference = $bag->referencesFor(EvidenceReference::TYPE_MARKETING_OBSERVATION)[0];

    expect($bag->referenceKeys(EvidenceReference::TYPE_MARKETING_OBSERVATION))->toBe(['obs-1'])
        ->and($bag->referenceKeys(EvidenceReference::TYPE_CONNECTOR_SYNC_RUN))->toBe(['sync-1'])
        ->and($reference->confidence)->toBe(0.82)
        ->and($reference->weight)->toBe(0.74)
        ->and(data_get($reference->metadata, 'source_metadata.api_key'))->toBe('[redacted]')
        ->and($bag->toEvidence()->toArray()['marketing_observation_ids'])->toBe(['obs-1']);
});

it('normalizes page snapshots into evidence references', function (): void {
    $snapshot = new PageSnapshot();
    $snapshot->forceFill([
        'id' => 'snapshot-1',
        'requested_url' => 'https://example.com/pricing',
        'final_url' => 'https://example.com/pricing',
        'canonical_url' => 'https://example.com/pricing',
        'http_status' => 200,
        'content_changed' => true,
        'fetched_at' => CarbonImmutable::parse('2026-07-03 12:30:00'),
        'metadata_json' => ['authorization' => 'Bearer secret', 'source' => 'crawler'],
    ]);

    $bag = (new EvidenceNormalizer())->normalize($snapshot);
    $reference = $bag->referencesFor(EvidenceReference::TYPE_PAGE_SNAPSHOT)[0];

    expect($bag->referenceKeys(EvidenceReference::TYPE_PAGE_SNAPSHOT))->toBe(['snapshot-1'])
        ->and($reference->label)->toBe('https://example.com/pricing')
        ->and(data_get($reference->metadata, 'metadata.authorization'))->toBe('[redacted]')
        ->and(data_get($reference->metadata, 'metadata.source'))->toBe('crawler')
        ->and($bag->toEvidence()->toArray()['page_snapshot_ids'])->toBe(['snapshot-1']);
});

it('normalizes graph references as evidence with legacy-compatible mappings where safe', function (): void {
    $report = IntelligenceGraphReference::report('report-1', 'Weekly report', metadata: [
        'client_secret' => 'secret',
        'visible' => 'kept',
    ]);
    $page = IntelligenceGraphReference::page('/pricing', 'Pricing page');

    $bag = (new EvidenceNormalizer())->normalize([$report, $page]);
    $reportReference = $bag->referencesFor(EvidenceReference::TYPE_REPORT)[0];
    $resourceReference = $bag->referencesFor(EvidenceReference::TYPE_GRAPH_REFERENCE)[0];
    $legacy = $bag->toEvidence()->toArray();

    expect($reportReference->toGraphReference()->graphKey())->toBe('report:report-1')
        ->and(data_get($reportReference->metadata, 'client_secret'))->toBe('[redacted]')
        ->and(data_get($reportReference->metadata, 'visible'))->toBe('kept')
        ->and($resourceReference->key)->toBe('page:/pricing')
        ->and($legacy['report_ids'])->toBe(['report-1'])
        ->and($legacy['resource_references']['graph_reference'])->toBe(['page:/pricing']);
});

it('carries confidence weight reason provenance and redacted metadata on evidence', function (): void {
    $reference = EvidenceReference::performanceSignal(
        'signal-1',
        'Sessions grew',
        confidence: 88,
        weight: 1.75,
        reason: 'Observed week-over-week lift',
        metadata: ['nested' => ['refresh_token' => 'secret'], 'visible' => 'kept'],
        provenance: ['authorization' => 'Bearer secret', 'provider' => 'ga4'],
    );

    expect($reference->confidence)->toBe(0.88)
        ->and($reference->weight)->toBe(1.75)
        ->and($reference->reason)->toBe('Observed week-over-week lift')
        ->and(data_get($reference->metadata, 'nested.refresh_token'))->toBe('[redacted]')
        ->and(data_get($reference->metadata, 'visible'))->toBe('kept')
        ->and(data_get($reference->provenance, 'authorization'))->toBe('[redacted]')
        ->and(data_get($reference->provenance, 'provider'))->toBe('ga4');
});

it('projects fake collector and projector output for normalized evidence', function (): void {
    $collector = (new FakeEvidenceCollector())
        ->add(EvidenceReference::pageSnapshot('snapshot-1'))
        ->add(new MarketingEvidence(marketingObservationIds: ['obs-1']));

    $bag = $collector->collect(EvidenceReference::report('report-1'));
    $projector = (new FakeEvidenceProjector())->project($bag);
    $output = $projector->evidence();

    expect($bag->referenceKeys(EvidenceReference::TYPE_PAGE_SNAPSHOT))->toBe(['snapshot-1'])
        ->and($bag->referenceKeys(EvidenceReference::TYPE_MARKETING_OBSERVATION))->toBe(['obs-1'])
        ->and($bag->referenceKeys(EvidenceReference::TYPE_REPORT))->toBe(['report-1'])
        ->and($output['references'])->toHaveCount(3)
        ->and(data_get($output, 'legacy_evidence.page_snapshot_ids'))->toBe(['snapshot-1'])
        ->and(data_get($output, 'legacy_evidence.marketing_observation_ids'))->toBe(['obs-1'])
        ->and(data_get($output, 'legacy_evidence.report_ids'))->toBe(['report-1']);
});

it('keeps phase five free of generic evidence database tables', function (): void {
    $migrationPaths = collect(glob(base_path('database/migrations/*.php')) ?: []);
    $tableNames = $migrationPaths
        ->flatMap(function (string $path): array {
            preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($path) ?: '', $matches);

            return $matches[1] ?? [];
        })
        ->values()
        ->all();

    expect($tableNames)->not->toContain('evidence_references')
        ->and($tableNames)->not->toContain('evidence_bags')
        ->and($tableNames)->not->toContain('evidence_items')
        ->and($tableNames)->not->toContain('normalized_evidence')
        ->and($tableNames)->not->toContain('intelligence_evidence')
        ->and($tableNames)->not->toContain('intelligence_evidence_references')
        ->and($migrationPaths->filter(fn (string $path): bool => str_contains(basename($path), 'evidence_engine') || str_contains(basename($path), 'evidence_reference') || str_contains(basename($path), 'evidence_bag'))->all())->toBe([]);
});
