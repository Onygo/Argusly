<?php

use App\Jobs\PageIntelligence\AnalyzePageEntitiesJob;
use App\Jobs\PageIntelligence\AnalyzePageSentimentJob;
use App\Jobs\PageIntelligence\CalculateBasicPageScoresJob;
use App\Jobs\PageIntelligence\CalculatePagePrValueJob;
use App\Jobs\PageIntelligence\ClassifyPageTopicsJob;
use App\Jobs\PageIntelligence\EmitPageSignalsJob;
use App\Jobs\PageIntelligence\EvaluatePageAlertRulesJob;
use App\Jobs\PageIntelligence\ExtractPageContentJob;
use App\Jobs\PageIntelligence\FetchMonitoredPageJob;
use App\Jobs\PageIntelligence\LinkLlmTrackingSourcesToPagesJob;
use App\Jobs\PageIntelligence\RunPageRelationshipMatchingJob;
use App\Models\AlertRule;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Organization;
use App\Models\PageAlert;
use App\Models\PageContentExtraction;
use App\Models\PageSerpObservation;
use App\Models\PageSnapshot;
use App\Models\User;
use App\Services\PageIntelligence\PageIntelligencePipelineOrchestrator;
use App\Services\PageIntelligence\Serp\RecordSerpObservationAction;
use App\Services\PageIntelligence\Serp\SerpObservationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\Middleware\WithoutOverlapping;

uses(RefreshDatabase::class);

it('defines all page intelligence queue names', function (): void {
    expect(config('page_intelligence.queues'))->toHaveKeys([
        'discover',
        'fetch',
        'extract',
        'analyze',
        'score',
        'signal',
        'alert',
    ]);
});

it('uses the Page Intelligence queue and overlap contract for LLM source linking', function (): void {
    $job = new LinkLlmTrackingSourcesToPagesJob(123);

    expect($job->queue)->toBe(config('page_intelligence.queues.signal'))
        ->and($job->tries)->toBe(2)
        ->and($job->timeout)->toBe(120)
        ->and($job->backoff)->toBe(30)
        ->and(collect($job->middleware())->contains(fn (object $middleware): bool => $middleware instanceof WithoutOverlapping))->toBeTrue();
});

it('dispatches the expected snapshot processing chain', function (): void {
    Bus::fake();
    $snapshot = PageSnapshot::factory()->create();

    app(PageIntelligencePipelineOrchestrator::class)->dispatchSnapshotPipeline($snapshot);

    Bus::assertChained([
        ExtractPageContentJob::class,
        AnalyzePageEntitiesJob::class,
        ClassifyPageTopicsJob::class,
        AnalyzePageSentimentJob::class,
        RunPageRelationshipMatchingJob::class,
        CalculateBasicPageScoresJob::class,
        CalculatePagePrValueJob::class,
        EmitPageSignalsJob::class,
        EvaluatePageAlertRulesJob::class,
    ]);
});

it('dispatches fetch with pipeline continuation for monitored pages', function (): void {
    Queue::fake();
    $page = MonitoredPage::factory()->create();

    app(PageIntelligencePipelineOrchestrator::class)->dispatchFetch($page);

    Queue::assertPushed(FetchMonitoredPageJob::class, fn (FetchMonitoredPageJob $job): bool => $job->monitoredPageId === $page->id && $job->continuePipeline);
});

it('authorizes page intelligence models by organization', function (): void {
    $organization = Organization::query()->create([
        'name' => 'Page Intelligence Policy Org',
        'slug' => 'page-intelligence-policy-org',
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);
    $otherOrganization = Organization::query()->create([
        'name' => 'Other Page Intelligence Policy Org',
        'slug' => 'other-page-intelligence-policy-org',
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);
    $user = User::factory()->create(['organization_id' => $organization->id, 'role' => 'editor']);
    $otherUser = User::factory()->create(['organization_id' => $otherOrganization->id, 'role' => 'editor']);
    $page = MonitoredPage::factory()->create(['organization_id' => $organization->id]);
    $source = MonitoredSource::factory()->create(['organization_id' => $organization->id]);
    $rule = AlertRule::factory()->create(['organization_id' => $organization->id]);
    $alert = PageAlert::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $rule->workspace_id,
        'client_site_id' => $rule->client_site_id,
        'alert_rule_id' => $rule->id,
    ]);

    expect($user->can('view', $page))->toBeTrue()
        ->and($user->can('view', $source))->toBeTrue()
        ->and($user->can('view', $rule))->toBeTrue()
        ->and($user->can('view', $alert))->toBeTrue()
        ->and($otherUser->can('view', $page))->toBeFalse()
        ->and($otherUser->can('view', $source))->toBeFalse()
        ->and($otherUser->can('view', $rule))->toBeFalse()
        ->and($otherUser->can('view', $alert))->toBeFalse();
});

it('prunes stored raw HTML without deleting monitored page identity', function (): void {
    Storage::fake('local');
    config()->set('page_intelligence.retention.raw_html_days', 1);
    config()->set('page_intelligence.retention.snapshot_days', 365);

    $page = MonitoredPage::factory()->create();
    $snapshot = PageSnapshot::factory()->forPage($page)->create([
        'fetched_at' => now()->subDays(3),
        'raw_html_path' => 'page-snapshots/'.$page->id.'/1.html',
        'raw_html' => null,
    ]);
    Storage::disk('local')->put((string) $snapshot->raw_html_path, '<html>old</html>');

    $this->artisan('page-intelligence:prune')->assertSuccessful();

    expect(MonitoredPage::query()->whereKey($page->id)->exists())->toBeTrue()
        ->and($snapshot->refresh()->raw_html_path)->toBeNull();
    Storage::disk('local')->assertMissing('page-snapshots/'.$page->id.'/1.html');
});

it('soft deletes pruned extractions without retaining stale artifact paths', function (): void {
    Storage::fake('local');
    config()->set('page_intelligence.retention.snapshot_days', 1);

    $page = MonitoredPage::factory()->create();
    $snapshot = PageSnapshot::factory()->forPage($page)->create([
        'fetched_at' => now()->subDays(3),
    ]);
    $extraction = PageContentExtraction::factory()->forSnapshot($snapshot)->create([
        'main_text_path' => 'page-extractions/'.$snapshot->id.'/main.txt',
        'main_html_path' => 'page-extractions/'.$snapshot->id.'/main.html',
        'main_text' => null,
        'main_html' => null,
    ]);
    Storage::disk('local')->put((string) $extraction->main_text_path, 'old text');
    Storage::disk('local')->put((string) $extraction->main_html_path, '<main>old</main>');

    $this->artisan('page-intelligence:prune')->assertSuccessful();

    $pruned = PageContentExtraction::withTrashed()->findOrFail($extraction->id);

    expect($pruned->deleted_at)->not->toBeNull()
        ->and($pruned->main_text_path)->toBeNull()
        ->and($pruned->main_html_path)->toBeNull()
        ->and(data_get($pruned->metadata_json, 'retention.status'))->toBe('pruned');

    Storage::disk('local')->assertMissing('page-extractions/'.$snapshot->id.'/main.txt');
    Storage::disk('local')->assertMissing('page-extractions/'.$snapshot->id.'/main.html');
});

it('stores long SERP query hashes and avoids long query indexes', function (): void {
    $workspace = MonitoredPage::factory()->create()->workspace;
    $query = str_repeat('page intelligence ', 30);

    $observation = app(RecordSerpObservationAction::class)->execute($workspace, new SerpObservationResult(
        query: $query,
        pageUrl: 'https://example.com/long-query',
        title: 'Long query result',
        snippet: 'Snippet',
        position: 1,
        absolutePosition: 1,
    ));

    expect($observation->query)->toBe(trim($query))
        ->and($observation->query_hash)->toBe(hash('sha256', mb_strtolower(trim($query))))
        ->and($observation->page_url_hash)->toBe(hash('sha256', $observation->page_url))
        ->and(Schema::hasColumn('page_serp_observations', 'query_hash'))->toBeTrue();
});
