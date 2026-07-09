<?php

use App\Models\MarketingOperatingLink;
use App\Models\PageIntelligenceReport;
use App\Models\PageScore;
use App\Models\ScheduledPageIntelligenceBriefing;
use App\Services\AgenticMarketing\Intelligence\MarketingEvidence;
use App\Services\AgenticMarketing\Intelligence\MarketingInsight;
use App\Services\AgenticMarketing\Intelligence\MarketingRecommendation;
use App\Services\AgenticMarketing\Intelligence\ReasoningSnapshot;
use App\Services\PageIntelligence\Reports\ReportBuilder;
use App\Services\PageIntelligence\Scoring\ScoreEvidence;
use App\Services\PerformanceIntelligence\PerformanceSignal;
use App\Support\Intelligence\EvidenceReference;
use App\Support\Intelligence\IntelligenceGraphEdge;
use App\Support\Intelligence\IntelligenceSignal;
use App\Support\Intelligence\ReasoningResult;
use App\Support\Intelligence\TimeWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

it('maps performance signals to canonical intelligence signals without changing legacy output', function (): void {
    $signal = new PerformanceSignal(
        key: 'performance-intelligence:sessions-growth',
        type: 'traffic_trend',
        subjectType: 'page',
        subjectKey: 'page-1',
        subjectName: 'Pricing page',
        metricKey: 'sessions',
        direction: 'growth',
        confidence: 0.91,
        periodStart: CarbonImmutable::parse('2026-07-01 00:00:00'),
        periodEnd: CarbonImmutable::parse('2026-07-07 23:59:59'),
        sourceMetrics: ['current' => ['value' => 180], 'previous' => ['value' => 120]],
        observationIds: ['obs-1', 'obs-2'],
        explanation: 'Sessions grew with traced observations.',
        metadata: [
            'current_value' => 180,
            'previous_value' => 120,
            'absolute_change' => 60,
        ],
    );
    $legacy = $signal->toArray();

    $projected = $signal->toIntelligenceSignal();

    expect($signal->toArray())->toBe($legacy)
        ->and($projected)->toBeInstanceOf(IntelligenceSignal::class)
        ->and($projected->key)->toBe($signal->key)
        ->and($projected->subject->graphKey())->toBe('page:page-1')
        ->and($projected->metric)->toBe('sessions')
        ->and($projected->value)->toBe(180.0)
        ->and($projected->baseline)->toBe(120.0)
        ->and($projected->delta)->toBe(60.0)
        ->and($projected->evidence->referenceIds('performance_signal_keys'))->toBe([$signal->key])
        ->and($projected->evidence->referenceIds('marketing_observation_ids'))->toBe(['obs-1', 'obs-2'])
        ->and(collect($projected->graphReferences)->map(fn ($reference): string => $reference->graphKey())->all())->toBe([
            'observation:obs-1',
            'observation:obs-2',
        ])
        ->and(data_get($projected->toArray(), 'time_window.periods_count'))->toBe(7);
});

it('exposes evidence bags from existing marketing and score evidence payloads', function (): void {
    $window = TimeWindow::between('2026-07-01', '2026-07-07');
    $marketing = new MarketingEvidence(
        marketingObservationIds: ['obs-1'],
        pageSnapshotIds: ['snapshot-1'],
        pageScoreIds: ['score-1'],
        trendIds: ['trend-1'],
        performanceSignalKeys: ['signal-1'],
        pageIntelligenceInputIds: ['page_serp_observations' => ['serp-1']],
        reportIds: ['report-1'],
        scheduledBriefingIds: ['briefing-1'],
        sourceMetrics: ['sessions' => ['delta' => 60]],
    );
    $score = new ScoreEvidence(
        marketingObservationIds: ['obs-1'],
        pageSnapshotIds: ['snapshot-1'],
        trendIds: ['trend-1'],
        performanceSignalKeys: ['signal-1'],
        pageIntelligenceInputIds: ['page_geo_observations' => ['geo-1']],
        sourceMetrics: ['score' => ['value' => 82]],
    );

    $marketingBag = $marketing->toEvidenceBag($window);
    $scoreBag = $score->toEvidenceBag($window);

    expect(MarketingEvidence::fromEvidence($marketingBag->toEvidence())->toArray())->toBe($marketing->toArray())
        ->and(ScoreEvidence::fromEvidence($scoreBag->toEvidence())->toArray())->toBe($score->toArray())
        ->and($marketingBag->referenceKeys(EvidenceReference::TYPE_REPORT))->toBe(['report-1'])
        ->and($marketingBag->referenceKeys(EvidenceReference::TYPE_BRIEFING))->toBe(['briefing-1'])
        ->and(data_get($marketingBag->referencesFor(EvidenceReference::TYPE_MARKETING_OBSERVATION)[0]->toArray(), 'time_window.periods_count'))->toBe(7)
        ->and(data_get($scoreBag->toArray(), 'legacy_evidence.page_intelligence_input_ids.page_geo_observations'))->toBe(['geo-1']);
});

it('projects marketing insights and recommendations into reasoning traces and graph edges', function (): void {
    $window = TimeWindow::between('2026-07-01', '2026-07-07');
    $evidence = new MarketingEvidence(
        marketingObservationIds: ['obs-1'],
        performanceSignalKeys: ['performance-intelligence:sessions-growth'],
        sourceMetrics: ['sessions' => ['current' => 180, 'previous' => 120]],
    );
    $insight = new MarketingInsight(
        key: 'insight:traffic-growth',
        type: 'opportunity',
        title: 'Traffic is growing',
        summary: 'Traffic is growing with traced observations.',
        direction: 'growth',
        severity: 72,
        confidence: 0.82,
        evidence: $evidence,
        affectedPages: [['id' => 'page-1', 'title' => 'Pricing page']],
        affectedTopics: ['AI Visibility'],
    );
    $recommendation = new MarketingRecommendation(
        key: 'recommendation:refresh-pricing',
        type: 'content_improvement',
        title: 'Refresh the pricing page',
        summary: 'Improve the page while traffic is rising.',
        priority: 76,
        confidence: 0.8,
        evidence: $evidence,
        recommendedActions: ['Refresh page proof', 'Publish social support'],
        supportingInsightKeys: [$insight->key],
        affectedPages: [['id' => 'page-1', 'title' => 'Pricing page']],
    );
    $insightLegacy = $insight->toArray();
    $recommendationLegacy = $recommendation->toArray();

    $insightResult = $insight->toReasoningResult($window);
    $recommendationResult = $recommendation->toReasoningResult($window);

    expect($insight->toArray())->toBe($insightLegacy)
        ->and($recommendation->toArray())->toBe($recommendationLegacy)
        ->and($insightResult)->toBeInstanceOf(ReasoningResult::class)
        ->and($recommendationResult)->toBeInstanceOf(ReasoningResult::class)
        ->and($insightResult->trace->transitions())->toBe(['signal_to_insight'])
        ->and($recommendationResult->trace->transitions())->toBe(['insight_to_recommendation'])
        ->and(collect($recommendationResult->graphEdges())->map(fn (IntelligenceGraphEdge $edge): string => $edge->type)->all())
        ->toContain('recommends', 'acts_on', 'evidences')
        ->and($recommendationResult->evidence->referenceKeys(EvidenceReference::TYPE_MARKETING_OBSERVATION))->toBe(['obs-1'])
        ->and($recommendationResult->evidence->referenceKeys(EvidenceReference::TYPE_MARKETING_RECOMMENDATION))->toBe([$recommendation->key]);
});

it('aggregates reasoning snapshot projections without changing snapshot output', function (): void {
    $windowStart = CarbonImmutable::parse('2026-07-01 00:00:00');
    $windowEnd = CarbonImmutable::parse('2026-07-07 23:59:59');
    $evidence = new MarketingEvidence(marketingObservationIds: ['obs-1']);
    $insight = new MarketingInsight(
        key: 'insight:snapshot',
        type: 'opportunity',
        title: 'Snapshot insight',
        summary: 'Traceable snapshot insight.',
        direction: 'growth',
        severity: 60,
        confidence: 0.7,
        evidence: $evidence,
    );
    $recommendation = new MarketingRecommendation(
        key: 'recommendation:snapshot',
        type: 'content_improvement',
        title: 'Snapshot recommendation',
        summary: 'Traceable snapshot recommendation.',
        priority: 70,
        confidence: 0.72,
        evidence: $evidence,
        supportingInsightKeys: [$insight->key],
    );
    $snapshot = new ReasoningSnapshot(
        workspaceId: 'workspace-1',
        clientSiteId: 'site-1',
        periodStart: $windowStart,
        periodEnd: $windowEnd,
        granularity: 'daily',
        generatedAt: CarbonImmutable::parse('2026-07-08 12:00:00'),
        modelKey: 'deterministic',
        modelVersion: 'v1',
        insights: [$insight],
        recommendations: [$recommendation],
        evidence: $evidence,
    );
    $legacy = $snapshot->toArray();

    expect($snapshot->toArray())->toBe($legacy)
        ->and($snapshot->reasoningResults())->toHaveCount(2)
        ->and($snapshot->graphEdges())->not->toBeEmpty()
        ->and($snapshot->evidenceBag()->referenceKeys(EvidenceReference::TYPE_MARKETING_OBSERVATION))->toBe(['obs-1'])
        ->and($snapshot->timeWindow()->toArray())->toMatchArray([
            'period_start' => '2026-07-01 00:00:00',
            'period_end' => '2026-07-07 23:59:59',
            'periods_count' => 7,
        ]);
});

it('exposes report and briefing windows evidence and graph projections from existing payloads', function (): void {
    $briefing = new ScheduledPageIntelligenceBriefing();
    $briefing->forceFill([
        'id' => 'briefing-1',
        'workspace_id' => 'workspace-1',
        'client_site_id' => 'site-1',
        'report_type' => ReportBuilder::TYPE_WEEKLY,
        'market_pack_key' => 'agentic_saas',
        'frequency' => ScheduledPageIntelligenceBriefing::FREQUENCY_WEEKLY,
        'day_of_week' => 1,
        'timezone' => 'UTC',
        'is_active' => true,
        'next_run_at' => Carbon::parse('2026-07-13 09:00:00'),
    ]);
    $report = new PageIntelligenceReport();
    $report->forceFill([
        'id' => 'report-1',
        'workspace_id' => 'workspace-1',
        'client_site_id' => 'site-1',
        'report_type' => ReportBuilder::TYPE_WEEKLY,
        'title' => 'Weekly Intelligence Briefing',
        'status' => PageIntelligenceReport::STATUS_GENERATED,
        'snapshot_version' => 1,
        'template_version' => ReportBuilder::TEMPLATE_VERSION,
        'period_start' => Carbon::parse('2026-07-06')->startOfDay(),
        'period_end' => Carbon::parse('2026-07-12')->endOfDay(),
        'payload_json' => [
            'evidence_links' => [[
                'label' => 'Pricing page',
                'page_id' => 'page-1',
                'source_model' => PageScore::class,
                'source_id' => 'score-1',
                'canonical_url' => 'https://example.com/pricing',
            ]],
        ],
        'provenance_json' => [
            'source_row_ids' => [
                'page_snapshots' => ['snapshot-1'],
                'page_scores' => ['score-1'],
                'page_serp_observations' => ['serp-1'],
            ],
        ],
        'scheduled_page_intelligence_briefing_id' => 'briefing-1',
    ]);
    $briefing->setRelation('generatedReports', collect([$report]));
    $reportLegacy = $report->toArray();
    $briefingLegacy = $briefing->toArray();

    $reportBag = $report->evidenceBag();
    $briefingWindow = $briefing->reportTimeWindowForRun(Carbon::parse('2026-07-13 09:00:00'));

    expect($report->toArray())->toBe($reportLegacy)
        ->and($briefing->toArray())->toBe($briefingLegacy)
        ->and($briefingWindow->start->toDateString())->toBe('2026-07-06')
        ->and($briefingWindow->end->toDateString())->toBe('2026-07-12')
        ->and($report->timeWindow()?->toArray())->toMatchArray([
            'period_start' => '2026-07-06 00:00:00',
            'period_end' => '2026-07-12 23:59:59',
            'periods_count' => 7,
        ])
        ->and($reportBag->referenceKeys(EvidenceReference::TYPE_REPORT))->toBe(['report-1'])
        ->and($reportBag->referenceKeys(EvidenceReference::TYPE_BRIEFING))->toBe(['briefing-1'])
        ->and($reportBag->referenceKeys(EvidenceReference::TYPE_PAGE_SNAPSHOT))->toBe(['snapshot-1'])
        ->and($reportBag->referenceKeys(EvidenceReference::TYPE_PAGE_SCORE))->toBe(['score-1'])
        ->and(data_get($reportBag->toArray(), 'legacy_evidence.page_intelligence_input_ids.page_serp_observations'))->toBe(['serp-1'])
        ->and(collect($report->toGraphEdges())->map(fn (IntelligenceGraphEdge $edge): string => $edge->type)->all())->toContain('reports', 'evidences')
        ->and($briefing->toGraphEdges(Carbon::parse('2026-07-13 09:00:00'))[0]->type)->toBe('reports');
});

it('projects marketing operating system links into intelligence graph edges', function (): void {
    $link = new MarketingOperatingLink();
    $link->forceFill([
        'id' => 'link-1',
        'marketing_objective_id' => 'objective-1',
        'marketing_initiative_id' => 'initiative-1',
        'relationship_type' => MarketingOperatingLink::RELATION_RECOMMENDS,
        'resource_type' => 'agentic_marketing_recommendation',
        'resource_id' => 'recommendation-1',
        'resource_key' => 'agentic_marketing_recommendation:recommendation-1',
        'resource_title' => 'Refresh pricing page',
        'resource_model' => MarketingRecommendation::class,
        'confidence_score' => 0.82,
        'metadata_json' => [
            'evidence' => [
                'marketing_observation_ids' => ['obs-1'],
                'source_metrics' => ['sessions' => ['delta' => 60]],
            ],
        ],
    ]);

    $edge = $link->toIntelligenceGraphEdge();

    expect($edge->type)->toBe('recommends')
        ->and($edge->source->graphKey())->toBe('initiative:initiative-1')
        ->and($edge->target->graphKey())->toBe('recommendation:recommendation-1')
        ->and($edge->confidence)->toBe(0.82)
        ->and($edge->evidence->referenceIds('marketing_observation_ids'))->toBe(['obs-1'])
        ->and($edge->toArray()['intelligence_stage'])->toBe('recommendation');
});
