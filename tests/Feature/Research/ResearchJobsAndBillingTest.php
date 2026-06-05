<?php

use App\Enums\ResearchProjectStatus;
use App\Enums\ResearchSourceFetchStatus;
use App\Enums\ResearchSourceType;
use App\Jobs\Research\ExtractResearchFindingsJob;
use App\Jobs\Research\FetchResearchSourceJob;
use App\Jobs\Research\RunResearchJob;
use App\Models\ClientSite;
use App\Models\CreditReservation;
use App\Models\Organization;
use App\Models\ResearchFinding;
use App\Models\ResearchProject;
use App\Models\ResearchSource;
use App\Models\Workspace;
use App\Services\CreditReservationService;
use App\Services\CreditWalletService;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;
use App\Services\Llm\LlmManager;
use App\Services\Research\ResearchSummaryService;
use App\Services\Research\SourceIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('captures reserved credits when source fetch succeeds with billing enabled', function () {
    [$site, $project, $source] = makeResearchBillingJobContext();

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 20,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['event' => 'research-test-seed'],
    );

    $ingestion = \Mockery::mock(SourceIngestionService::class);
    $ingestion->shouldReceive('fetchSource')->once()->andReturnUsing(function (ResearchSource $source): ResearchSource {
        $source->update([
            'fetch_status' => ResearchSourceFetchStatus::FETCHED,
            'content_text' => 'Fetched source body with usable text.',
            'fetched_at' => now(),
            'meta' => array_replace_recursive(is_array($source->meta) ? $source->meta : [], [
                'fetch' => [
                    'status' => ResearchSourceFetchStatus::FETCHED->value,
                    'fetched_at' => now()->toIso8601String(),
                ],
                'extraction' => [
                    'status' => 'pending',
                ],
            ]),
        ]);

        return $source->fresh();
    });

    app()->instance(SourceIngestionService::class, $ingestion);

    Queue::fake();

    $job = new FetchResearchSourceJob((string) $source->id);
    $job->handle(
        app(SourceIngestionService::class),
        app(CreditReservationService::class),
        app(CreditWalletService::class),
    );

    $reservation = CreditReservation::query()
        ->where('context_type', ResearchSource::class)
        ->where('context_id', $source->id)
        ->first();

    expect($reservation)->not->toBeNull()
        ->and((string) $reservation->status)->toBe(CreditReservation::STATUS_CAPTURED);

    Queue::assertPushed(ExtractResearchFindingsJob::class);
    Queue::assertPushed(RunResearchJob::class);
});

it('releases reserved credits when source fetch fails with billing enabled', function () {
    [$site, $project, $source] = makeResearchBillingJobContext('research-billing-fail');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 20,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['event' => 'research-test-seed'],
    );

    $ingestion = \Mockery::mock(SourceIngestionService::class);
    $ingestion->shouldReceive('fetchSource')->once()->andReturnUsing(function (ResearchSource $source): ResearchSource {
        $source->update([
            'fetch_status' => ResearchSourceFetchStatus::FAILED,
            'meta' => array_replace_recursive(is_array($source->meta) ? $source->meta : [], [
                'fetch' => [
                    'status' => ResearchSourceFetchStatus::FAILED->value,
                    'failed_at' => now()->toIso8601String(),
                    'error' => 'Synthetic fetch failure for billing test.',
                ],
            ]),
        ]);

        return $source->fresh();
    });

    app()->instance(SourceIngestionService::class, $ingestion);

    Queue::fake();

    $job = new FetchResearchSourceJob((string) $source->id);
    $job->handle(
        app(SourceIngestionService::class),
        app(CreditReservationService::class),
        app(CreditWalletService::class),
    );

    $reservation = CreditReservation::query()
        ->where('context_type', ResearchSource::class)
        ->where('context_id', $source->id)
        ->first();

    expect($reservation)->not->toBeNull()
        ->and((string) $reservation->status)->toBe(CreditReservation::STATUS_RELEASED)
        ->and((string) data_get($source->fresh()->meta, 'fetch.status'))->toBe(ResearchSourceFetchStatus::FAILED->value);

    Queue::assertNotPushed(ExtractResearchFindingsJob::class);
    Queue::assertPushed(RunResearchJob::class);
});

it('completes research project lifecycle when sources are extracted and summary is generated', function () {
    [, $project, $source] = makeResearchRunJobContext();

    ResearchFinding::query()->create([
        'research_project_id' => $project->id,
        'research_source_id' => $source->id,
        'finding_type' => 'insight',
        'finding_text' => 'The fastest gains come from shorter approval cycles.',
        'confidence_score' => 0.88,
        'is_selected' => true,
    ]);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{}',
        json: [
            'executive_summary' => 'Approval-cycle reduction is the clearest opportunity.',
            'key_insights' => ['Shorter approval loops increase throughput.'],
            'open_questions' => ['Which stakeholders can be removed from approvals?'],
            'brief_enrichment' => [
                'angles' => ['Ops efficiency and publish velocity'],
                'risks' => ['Quality drift from fewer checkpoints'],
                'keyword_clusters' => ['content workflow'],
            ],
        ],
        usage: new LlmUsage(110, 60, 170),
        modelUsed: 'gpt-4.1-mini',
        providerName: 'openai',
        requestId: 'req-run-summary',
    ));
    app()->instance(LlmManager::class, $llm);

    $job = new RunResearchJob((string) $project->id);
    $job->handle(app(ResearchSummaryService::class));

    $project->refresh();

    expect((string) ($project->status?->value ?? $project->status))->toBe(ResearchProjectStatus::COMPLETED->value)
        ->and($project->completed_at)->not->toBeNull()
        ->and((string) $project->human_summary)->toContain('opportunity');
});

it('keeps completed research runs idempotent when rerun is not forced', function () {
    [, $project] = makeResearchRunJobContext(
        status: ResearchProjectStatus::COMPLETED,
        completedAt: now()->subMinute(),
    );

    $completedAt = $project->completed_at;

    $summary = \Mockery::mock(ResearchSummaryService::class);
    $summary->shouldNotReceive('persistSummary');

    $job = new RunResearchJob((string) $project->id, false);
    $job->handle($summary);

    $project->refresh();

    expect((string) ($project->status?->value ?? $project->status))->toBe(ResearchProjectStatus::COMPLETED->value)
        ->and((string) $project->completed_at)->toBe((string) $completedAt);
});

function makeResearchBillingJobContext(string $prefix = 'research-billing'): array
{
    $organization = Organization::query()->create([
        'name' => 'Research Billing Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Research Billing Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Research Billing Site',
        'site_url' => 'https://research-billing.example.com',
        'allowed_domains' => ['research-billing.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $project = ResearchProject::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Billing research project',
        'status' => ResearchProjectStatus::FETCHING,
        'config' => [
            'billing' => [
                'enabled' => true,
                'credits_per_source' => 2,
            ],
        ],
        'started_at' => now(),
    ]);

    $source = ResearchSource::query()->create([
        'research_project_id' => $project->id,
        'source_type' => ResearchSourceType::URL,
        'source_classification' => 'web',
        'url' => 'https://example.com/billing-source',
        'fetch_status' => ResearchSourceFetchStatus::PENDING,
        'meta' => [
            'extraction' => [
                'status' => 'pending',
            ],
        ],
    ]);

    return [$site, $project, $source];
}

function makeResearchRunJobContext(
    ResearchProjectStatus $status = ResearchProjectStatus::QUEUED,
    ?\Carbon\CarbonInterface $completedAt = null,
): array {
    $organization = Organization::query()->create([
        'name' => 'Research Run Org',
        'slug' => 'research-run-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Research Run Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Research Run Site',
        'site_url' => 'https://research-run.example.com',
        'allowed_domains' => ['research-run.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $project = ResearchProject::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Run research project',
        'status' => $status,
        'started_at' => now()->subMinute(),
        'completed_at' => $completedAt,
        'config' => [
            'billing' => [
                'enabled' => false,
                'credits_per_source' => 0,
            ],
        ],
    ]);

    $source = ResearchSource::query()->create([
        'research_project_id' => $project->id,
        'source_type' => ResearchSourceType::URL,
        'source_classification' => 'web',
        'url' => 'https://example.com/run-source',
        'content_text' => 'Run source content',
        'fetch_status' => ResearchSourceFetchStatus::FETCHED,
        'fetched_at' => now(),
        'meta' => [
            'extraction' => [
                'status' => 'succeeded',
            ],
        ],
    ]);

    return [$site, $project, $source];
}
