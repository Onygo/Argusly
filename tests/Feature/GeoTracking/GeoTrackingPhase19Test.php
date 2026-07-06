<?php

use App\Jobs\PageIntelligence\EvaluatePageAlertRulesJob;
use App\Jobs\PageIntelligence\LinkLlmTrackingSourcesToPagesJob;
use App\Models\AlertRule;
use App\Models\ClientSite;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\MonitoredPage;
use App\Models\Organization;
use App\Models\PageAlert;
use App\Models\PageGeoObservation;
use App\Models\PageScore;
use App\Models\PageSnapshot;
use App\Models\SignalEvent;
use App\Models\Workspace;
use App\Services\PageIntelligence\Alerts\PageAlertRuleEvaluator;
use App\Services\PageIntelligence\Geo\AnswerEngineAdapter;
use App\Services\PageIntelligence\Geo\AnswerEngineProviderRegistry;
use App\Services\PageIntelligence\Geo\PageGeoObservationBuilder;
use App\Services\PageIntelligence\PageIntelligenceScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('refreshes the Argusly Intelligence Score when GEO evidence links to a monitored page', function (): void {
    [$workspace, $site, $query, $run] = geoTrackingPhase19Run([
        'sources' => [
            ['url' => 'https://argusly.com/features?utm_source=chatgpt', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 1],
        ],
    ]);

    config()->set('page_intelligence.safety.dns_overrides', ['argusly.com' => ['93.184.216.34']]);

    $page = MonitoredPage::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'canonical_url' => 'https://argusly.com/features',
        'canonical_url_hash' => hash('sha256', 'https://argusly.com/features'),
        'first_seen_url' => 'https://argusly.com/features',
        'first_seen_url_hash' => hash('sha256', 'https://argusly.com/features'),
        'final_url' => 'https://argusly.com/features',
        'final_url_hash' => hash('sha256', 'https://argusly.com/features'),
        'domain' => 'argusly.com',
        'path' => '/features',
    ]);
    $snapshot = PageSnapshot::factory()->forPage($page)->create(['fetched_at' => now()]);

    app(PageGeoObservationBuilder::class)->buildForRun($run);

    $score = PageScore::query()
        ->where('page_snapshot_id', $snapshot->id)
        ->where('score_type', PageIntelligenceScoreCalculator::SCORE_TYPE)
        ->firstOrFail();

    expect(data_get($score->breakdown_json, 'components.geo_visibility.available'))->toBeTrue()
        ->and((float) data_get($score->breakdown_json, 'components.geo_visibility.score'))->toBeGreaterThan(0)
        ->and(data_get($score->metadata_json, 'missing_inputs'))->not->toContain('geo_visibility')
        ->and(PageGeoObservation::query()->where('llm_tracking_query_run_id', $run->id)->where('monitored_page_id', $page->id)->exists())->toBeTrue();
});

it('fires a page alert when a competitor displaces a client citation in AI answers', function (): void {
    $previousAt = now()->subHours(3);
    $currentAt = now()->subHour();

    [$workspace, $site, $query, $previousRun] = geoTrackingPhase19Run([
        'run_at' => $previousAt,
    ]);
    $currentRun = LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $query->id,
        'run_at' => $currentAt,
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'succeeded',
    ]);
    $page = MonitoredPage::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
    ]);
    PageSnapshot::factory()->forPage($page)->create();

    PageGeoObservation::factory()->forRun($previousRun)->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'llm_tracking_query_id' => $query->id,
        'query' => $query->query_text,
        'query_hash' => hash('sha256', mb_strtolower($query->query_text)),
        'cited_url' => null,
        'cited_url_hash' => hash('sha256', 'run-level|'.$previousRun->id),
        'monitored_page_id' => null,
        'page_snapshot_id' => null,
        'client_cited' => true,
        'competitors_cited' => false,
        'observed_at' => $previousAt,
    ]);

    $currentRunLevel = PageGeoObservation::factory()->forRun($currentRun)->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'llm_tracking_query_id' => $query->id,
        'query' => $query->query_text,
        'query_hash' => hash('sha256', mb_strtolower($query->query_text)),
        'cited_url' => null,
        'cited_url_hash' => hash('sha256', 'run-level|'.$currentRun->id),
        'monitored_page_id' => null,
        'page_snapshot_id' => null,
        'client_cited' => false,
        'competitors_cited' => true,
        'mentioned_competitors_json' => [['term' => 'AcmeSEO', 'type' => 'competitor']],
        'observed_at' => $currentAt,
    ]);
    PageGeoObservation::factory()->forRun($currentRun)->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'llm_tracking_query_id' => $query->id,
        'query' => $query->query_text,
        'query_hash' => hash('sha256', mb_strtolower($query->query_text)),
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $page->latestSnapshot()->value('id'),
        'client_cited' => false,
        'competitors_cited' => true,
        'observed_at' => $currentAt,
    ]);

    $rule = AlertRule::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'trigger' => AlertRule::TRIGGER_GEO_COMPETITOR_DISPLACED_CLIENT,
        'conditions_json' => ['window_minutes' => 1440],
        'severity' => 'high',
    ]);

    $alerts = app(PageAlertRuleEvaluator::class)->evaluateRule($rule);

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first())->toBeInstanceOf(PageAlert::class)
        ->and($alerts->first()->trigger)->toBe(AlertRule::TRIGGER_GEO_COMPETITOR_DISPLACED_CLIENT)
        ->and($alerts->first()->monitored_page_id)->toBe($page->id)
        ->and($alerts->first()->evidence_json['page_geo_observation_id'])->toBe($currentRunLevel->id);
});

it('dispatches GEO alert evaluation after LLM source linking commits', function (): void {
    Queue::fake([EvaluatePageAlertRulesJob::class]);
    [, , , $run] = geoTrackingPhase19Run([
        'sources' => [
            ['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 1],
        ],
    ]);

    (new LinkLlmTrackingSourcesToPagesJob($run->id))->handle(app(PageGeoObservationBuilder::class));

    Queue::assertPushed(
        EvaluatePageAlertRulesJob::class,
        fn (EvaluatePageAlertRulesJob $job): bool => $job->afterCommit === true
    );
});

it('resolves configured answer engine providers from the registry', function (): void {
    $adapter = new class implements AnswerEngineAdapter {
        public function observe(array $parameters): iterable
        {
            yield $parameters;
        }
    };

    config()->set('page_intelligence.providers.answer_engines', [
        'manual_test' => $adapter,
    ]);
    app()->forgetInstance(AnswerEngineProviderRegistry::class);

    $registry = app(AnswerEngineProviderRegistry::class);

    expect($registry->has('manual_test'))->toBeTrue()
        ->and($registry->get('manual_test'))->toBe($adapter);
});

it('does not let unsafe skipped GEO citation URLs inflate run-level citation count or score inputs', function (): void {
    [, , , $run] = geoTrackingPhase19Run([
        'sources' => [
            ['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 1],
            ['url' => 'http://127.0.0.1/private', 'domain' => '127.0.0.1', 'type' => 'website', 'position' => 2],
        ],
    ]);

    app(PageGeoObservationBuilder::class)->buildForRun($run);

    $runLevel = PageGeoObservation::query()
        ->where('llm_tracking_query_run_id', $run->id)
        ->whereNull('cited_url')
        ->firstOrFail();

    expect($runLevel->citation_count)->toBe(1)
        ->and(data_get($runLevel->breakdown_json, 'inputs.citation_count'))->toBe(1)
        ->and(PageGeoObservation::query()->where('llm_tracking_query_run_id', $run->id)->where('cited_domain', '127.0.0.1')->exists())->toBeFalse();
});

it('fires client gained citation alerts through the LLM source linking path', function (): void {
    geoTrackingPhase19LlmAlertScenario(
        AlertRule::TRIGGER_GEO_CITATION_GAIN,
        previousRunOverrides: [
            'sources' => [
                ['url' => 'https://competitor.acmeseo.com/guide', 'domain' => 'competitor.acmeseo.com', 'type' => 'website', 'position' => 1],
            ],
            'brand_mentioned' => false,
            'competitors_mentioned' => true,
        ],
        currentRunOverrides: [
            'sources' => [
                ['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 1],
            ],
            'brand_mentioned' => true,
            'competitors_mentioned' => false,
        ],
    );
});

it('fires client lost citation alerts through the LLM source linking path', function (): void {
    geoTrackingPhase19LlmAlertScenario(
        AlertRule::TRIGGER_GEO_CITATION_LOSS,
        previousRunOverrides: [
            'sources' => [
                ['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 1],
            ],
            'brand_mentioned' => true,
            'competitors_mentioned' => false,
        ],
        currentRunOverrides: [
            'sources' => [
                ['url' => 'https://competitor.acmeseo.com/guide', 'domain' => 'competitor.acmeseo.com', 'type' => 'website', 'position' => 1],
            ],
            'brand_mentioned' => false,
            'competitors_mentioned' => true,
        ],
    );
});

it('fires competitor gained citation alerts through the LLM source linking path', function (): void {
    geoTrackingPhase19LlmAlertScenario(
        AlertRule::TRIGGER_GEO_COMPETITOR_CITATION_GAIN,
        previousRunOverrides: [
            'sources' => [
                ['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 1],
            ],
            'brand_mentioned' => true,
            'competitors_mentioned' => false,
        ],
        currentRunOverrides: [
            'sources' => [
                ['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 1],
                ['url' => 'https://competitor.acmeseo.com/guide', 'domain' => 'competitor.acmeseo.com', 'type' => 'website', 'position' => 2],
            ],
            'brand_mentioned' => true,
            'competitors_mentioned' => true,
        ],
    );
});

it('fires competitor displaced client alerts through the LLM source linking path', function (): void {
    geoTrackingPhase19LlmAlertScenario(
        AlertRule::TRIGGER_GEO_COMPETITOR_DISPLACED_CLIENT,
        previousRunOverrides: [
            'sources' => [
                ['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 1],
            ],
            'brand_mentioned' => true,
            'competitors_mentioned' => false,
        ],
        currentRunOverrides: [
            'sources' => [
                ['url' => 'https://competitor.acmeseo.com/guide', 'domain' => 'competitor.acmeseo.com', 'type' => 'website', 'position' => 1],
            ],
            'brand_mentioned' => false,
            'competitors_mentioned' => true,
        ],
    );
});

/**
 * @param array<string,mixed> $runOverrides
 * @return array{0:Workspace,1:ClientSite,2:LlmTrackingQuery,3:LlmTrackingQueryRun}
 */
function geoTrackingPhase19Run(array $runOverrides = []): array
{
    config()->set('page_intelligence.safety.dns_overrides', [
        'argusly.com' => ['93.184.216.34'],
        'competitor.acmeseo.com' => ['93.184.216.34'],
    ]);

    $organization = Organization::query()->create([
        'name' => 'GEO Tracking Phase 19 Org',
        'slug' => 'geo-tracking-phase-19-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);
    $workspace = Workspace::query()->create([
        'name' => 'GEO Tracking Phase 19 Workspace',
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
        'run_at' => Carbon::parse('2026-07-04 08:00:00'),
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'succeeded',
        'answer_text' => 'Argusly is cited via https://argusly.com/features and compared with AcmeSEO.',
        'normalized_response' => 'Argusly is cited via https://argusly.com/features and compared with AcmeSEO.',
        'brand_hits' => [['term' => 'Argusly', 'count' => 1]],
        'competitor_hits' => [['term' => 'AcmeSEO', 'count' => 1]],
        'detected_brands' => [['term' => 'Argusly', 'type' => 'brand', 'present' => true]],
        'detected_competitors' => [['term' => 'AcmeSEO', 'type' => 'competitor', 'present' => true]],
        'sources' => [['url' => 'https://argusly.com/features', 'domain' => 'argusly.com', 'type' => 'website', 'position' => 1]],
        'brand_mentioned' => true,
        'urls_cited' => true,
        'competitors_mentioned' => true,
        'presence_score' => 1,
        'position_score' => 1,
        'citation_score' => 1,
        'sentiment_score' => 0.7,
        'sentiment_label' => 'positive',
        'competitive_score' => 0.6,
        'competitor_pressure_score' => 0.4,
        'citation_diversity_score' => 0.5,
        'model_confidence_score' => 0.85,
        'ai_visibility_score' => 0.82,
    ], $runOverrides));

    return [$workspace, $site, $query, $run];
}

/**
 * @param array<string,mixed> $previousRunOverrides
 * @param array<string,mixed> $currentRunOverrides
 */
function geoTrackingPhase19LlmAlertScenario(string $trigger, array $previousRunOverrides, array $currentRunOverrides): void
{
    Carbon::setTestNow(Carbon::parse('2026-07-05 12:00:00'));

    try {
        [$workspace, $site, $query, $previousRun] = geoTrackingPhase19Run(array_replace([
            'run_at' => Carbon::parse('2026-07-04 08:00:00'),
        ], $previousRunOverrides));

        (new LinkLlmTrackingSourcesToPagesJob($previousRun->id))->handle(app(PageGeoObservationBuilder::class));

        AlertRule::factory()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'trigger' => $trigger,
            'conditions_json' => ['window_minutes' => 4320],
            'severity' => 'high',
        ]);

        $currentRun = geoTrackingPhase19CreateRun($query, array_replace([
            'run_at' => Carbon::parse('2026-07-05 08:00:00'),
        ], $currentRunOverrides));

        (new LinkLlmTrackingSourcesToPagesJob($currentRun->id))->handle(app(PageGeoObservationBuilder::class));
        app(EvaluatePageAlertRulesJob::class)->handle(app(PageAlertRuleEvaluator::class));

        expect(PageAlert::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_site_id', $site->id)
            ->where('trigger', $trigger)
            ->count())->toBe(1);

        if ($trigger === AlertRule::TRIGGER_GEO_COMPETITOR_DISPLACED_CLIENT) {
            $event = SignalEvent::query()
                ->where('workspace_id', $workspace->id)
                ->where('metadata->event_key', 'competitor_displaced_client')
                ->firstOrFail();

            expect((float) $event->risk_score)->toBe(75.0);
        }
    } finally {
        Carbon::setTestNow();
    }
}

/**
 * @param array<string,mixed> $runOverrides
 */
function geoTrackingPhase19CreateRun(LlmTrackingQuery $query, array $runOverrides): LlmTrackingQueryRun
{
    return LlmTrackingQueryRun::query()->create(array_replace([
        'llm_tracking_query_id' => $query->id,
        'run_at' => Carbon::parse('2026-07-05 08:00:00'),
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'succeeded',
        'answer_text' => 'Argusly and AcmeSEO are compared in this AI answer.',
        'normalized_response' => 'Argusly and AcmeSEO are compared in this AI answer.',
        'brand_hits' => [['term' => 'Argusly', 'count' => 1]],
        'competitor_hits' => [['term' => 'AcmeSEO', 'count' => 1]],
        'detected_brands' => [['term' => 'Argusly', 'type' => 'brand', 'present' => true]],
        'detected_competitors' => [['term' => 'AcmeSEO', 'type' => 'competitor', 'present' => true]],
        'sources' => [],
        'brand_mentioned' => true,
        'urls_cited' => true,
        'competitors_mentioned' => true,
        'presence_score' => 1,
        'position_score' => 1,
        'citation_score' => 1,
        'sentiment_score' => 0.7,
        'sentiment_label' => 'positive',
        'competitive_score' => 0.6,
        'competitor_pressure_score' => 0.4,
        'citation_diversity_score' => 0.5,
        'model_confidence_score' => 0.85,
        'ai_visibility_score' => 0.82,
    ], $runOverrides));
}
