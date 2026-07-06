<?php

use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\PageSerpObservation;
use App\Models\SignalEvent;
use App\Models\Workspace;
use App\Services\PageIntelligence\Serp\RecordSerpObservationAction;
use App\Services\PageIntelligence\Serp\SerpObservationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('creates and links monitored pages for SERP result URLs', function (): void {
    $workspace = serpObservationWorkspace();

    $first = app(RecordSerpObservationAction::class)->execute($workspace, new SerpObservationResult(
        query: 'argusly page intelligence',
        pageUrl: 'https://example.com/page-intelligence?utm_source=serp',
        country: 'us',
        position: 2,
        title: 'Page Intelligence',
    ));

    $second = app(RecordSerpObservationAction::class)->execute($workspace, new SerpObservationResult(
        query: 'argusly page intelligence',
        pageUrl: 'https://example.com/page-intelligence?utm_campaign=brand',
        country: 'us',
        position: 3,
        title: 'Page Intelligence',
        observedAt: Carbon::now()->addMinute(),
    ));

    expect($first->page)->not->toBeNull()
        ->and($second->monitored_page_id)->toBe($first->monitored_page_id)
        ->and(MonitoredPage::query()->where('workspace_id', $workspace->id)->where('canonical_url', 'https://example.com/page-intelligence')->count())->toBe(1)
        ->and($first->page->source_type)->toBe('serp');
});

it('calculates and persists SERP visibility score breakdowns', function (): void {
    $workspace = serpObservationWorkspace();

    $observation = app(RecordSerpObservationAction::class)->execute($workspace, new SerpObservationResult(
        query: 'earned media value',
        pageUrl: 'https://example.com/pr-value',
        resultType: 'featured_snippet',
        position: 1,
        absolutePosition: 1,
        serpFeatures: ['featured_snippet'],
        searchVolume: 2400,
        keywordIntent: 'commercial',
    ));

    expect((float) $observation->visibility_score)->toBeGreaterThan(0)
        ->and($observation->breakdown_json)->toHaveKey('position')
        ->and($observation->breakdown_json)->toHaveKey('model');
});

it('rejects unsafe SERP result URLs before persistence', function (): void {
    $workspace = serpObservationWorkspace();

    expect(fn () => app(RecordSerpObservationAction::class)->execute($workspace, new SerpObservationResult(
        query: 'unsafe serp',
        pageUrl: 'http://127.0.0.1/private',
        title: 'Unsafe',
        snippet: 'Unsafe',
        position: 1,
        absolutePosition: 1,
    )))->toThrow(InvalidArgumentException::class);

    expect(PageSerpObservation::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(MonitoredPage::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
});

it('emits a Signal Event for SERP position gains', function (): void {
    $workspace = serpObservationWorkspace();
    $action = app(RecordSerpObservationAction::class);

    $action->execute($workspace, new SerpObservationResult(
        query: 'media monitoring software',
        pageUrl: 'https://example.com/media-monitoring',
        country: 'US',
        device: 'desktop',
        position: 8,
        absolutePosition: 8,
        observedAt: Carbon::parse('2026-07-02 10:00:00'),
    ));

    $action->execute($workspace, new SerpObservationResult(
        query: 'media monitoring software',
        pageUrl: 'https://example.com/media-monitoring',
        country: 'US',
        device: 'desktop',
        position: 3,
        absolutePosition: 3,
        observedAt: Carbon::parse('2026-07-03 10:00:00'),
    ));

    $event = SignalEvent::query()->get()->first(
        fn (SignalEvent $event): bool => data_get($event->metadata, 'source') === 'page_intelligence_serp'
            && data_get($event->metadata, 'direction') === 'gain'
    );

    expect($event)->not->toBeNull()
        ->and(data_get($event->metrics, 'position_delta'))->toBe(5);
});

it('stores competitor overlap from imported SERP observations', function (): void {
    $workspace = serpObservationWorkspace();

    $observation = app(RecordSerpObservationAction::class)->execute($workspace, new SerpObservationResult(
        query: 'pr analytics platform',
        pageUrl: 'https://example.com/pr-analytics',
        position: 4,
        competitorPresence: [
            ['domain' => 'competitor.example', 'position' => 1],
            ['domain' => 'another-competitor.example', 'position' => 2],
        ],
    ));

    expect($observation->competitor_presence_json)->toHaveCount(2)
        ->and($observation->competitor_presence_json[0]['domain'])->toBe('competitor.example');
});

it('keeps SERP observation history for the same query over time', function (): void {
    $workspace = serpObservationWorkspace();
    $action = app(RecordSerpObservationAction::class);

    $action->execute($workspace, new SerpObservationResult(
        query: 'geo visibility tracking',
        pageUrl: 'https://example.com/geo-visibility',
        position: 6,
        observedAt: Carbon::parse('2026-07-01 09:00:00'),
    ));

    $action->execute($workspace, new SerpObservationResult(
        query: 'geo visibility tracking',
        pageUrl: 'https://example.com/geo-visibility',
        position: 5,
        observedAt: Carbon::parse('2026-07-03 09:00:00'),
    ));

    expect(PageSerpObservation::query()->where('workspace_id', $workspace->id)->where('query', 'geo visibility tracking')->count())->toBe(2);
});

it('records manual SERP observations from the artisan command', function (): void {
    $workspace = serpObservationWorkspace();

    $this->artisan('page-intelligence:record-serp-observation', [
        'query' => 'argusly serp visibility',
        'url' => 'https://example.com/serp-visibility',
        '--workspace' => $workspace->id,
        '--position' => '2',
        '--country' => 'US',
        '--feature' => ['featured_snippet'],
        '--competitor' => ['competitor.example'],
    ])
        ->expectsOutput('SERP observation recorded.')
        ->assertSuccessful();

    expect(PageSerpObservation::query()->where('query', 'argusly serp visibility')->exists())->toBeTrue();
});

function serpObservationWorkspace(): Workspace
{
    config()->set('page_intelligence.safety.dns_overrides', ['example.com' => ['93.184.216.34']]);

    $source = MonitoredSource::factory()->create(['source_type' => 'serp']);

    return Workspace::query()->findOrFail($source->workspace_id);
}
