<?php

use App\Enums\ResearchSourceFetchStatus;
use App\Enums\ResearchSourceType;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\ResearchFinding;
use App\Models\ResearchProject;
use App\Models\ResearchSource;
use App\Models\Workspace;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;
use App\Services\Llm\LlmManager;
use App\Services\Research\ResearchExtractionService;
use App\Services\Research\ResearchSummaryService;
use App\Services\Research\SourceIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('fetches and normalizes source content successfully', function () {
    $source = makeResearchProcessingSource();

    Http::fake([
        'https://example.com/report' => Http::response(
            '<html><head><title>Quarterly Report</title></head><body><h1>Revenue up 14%</h1><p>Entity: PublishLayer.</p></body></html>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $updated = app(SourceIngestionService::class)->fetchSource($source);

    expect((string) ($updated->fetch_status?->value ?? $updated->fetch_status))->toBe(ResearchSourceFetchStatus::FETCHED->value)
        ->and((string) $updated->title)->toBe('Quarterly Report')
        ->and((string) $updated->content_text)->toContain('Revenue up 14%');
});

it('marks source fetch as failed safely when blocked host is used', function () {
    $source = makeResearchProcessingSource(url: 'http://localhost/internal');

    $updated = app(SourceIngestionService::class)->fetchSource($source);

    expect((string) ($updated->fetch_status?->value ?? $updated->fetch_status))->toBe(ResearchSourceFetchStatus::FAILED->value)
        ->and((string) data_get($updated->meta, 'fetch.error'))->toContain('blocked');
});

it('extracts findings through llm manager and stays idempotent on rerun', function () {
    $source = makeResearchProcessingSource(
        url: 'https://example.com/extraction',
        contentText: 'The market grew 20%. "Customers prefer concise summaries." ACME Corp was mentioned. What risks remain?',
        fetchStatus: ResearchSourceFetchStatus::FETCHED,
        meta: ['extraction' => ['status' => 'pending']]
    );

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{}',
        json: [
            'insights' => [['text' => 'Market growth accelerated this quarter', 'confidence' => 0.92, 'citations' => ['grew 20%']]],
            'statistics' => [['text' => 'Market grew 20%', 'confidence' => 0.96, 'citations' => ['20%']]],
            'quotes' => [['text' => 'Customers prefer concise summaries.', 'confidence' => 0.87, 'citations' => ['Customers prefer concise summaries']]],
            'entities' => [['text' => 'ACME Corp', 'confidence' => 0.8, 'citations' => ['ACME Corp']]],
            'questions' => [['text' => 'What risks remain?', 'confidence' => 0.7, 'citations' => ['What risks remain?']]],
        ],
        usage: new LlmUsage(100, 50, 150),
        modelUsed: 'gpt-4.1-mini',
        providerName: 'openai',
        requestId: 'req-research-1',
    ));

    app()->instance(LlmManager::class, $llm);

    $service = app(ResearchExtractionService::class);
    $firstCount = $service->extractFromSource($source->fresh(), false);
    $secondCount = $service->extractFromSource($source->fresh(), false);

    expect($firstCount)->toBe(5)
        ->and($secondCount)->toBe(5)
        ->and(ResearchFinding::query()->where('research_source_id', $source->id)->count())->toBe(5)
        ->and((string) data_get($source->fresh()->meta, 'extraction.status'))->toBe('succeeded');
});

it('generates and persists project summary from selected and high-confidence findings', function () {
    $source = makeResearchProcessingSource(
        url: 'https://example.com/summary',
        contentText: 'summary source text',
        fetchStatus: ResearchSourceFetchStatus::FETCHED,
        meta: ['extraction' => ['status' => 'succeeded']]
    );

    ResearchFinding::query()->create([
        'research_project_id' => $source->research_project_id,
        'research_source_id' => $source->id,
        'finding_type' => 'insight',
        'finding_text' => 'Teams want a faster publishing workflow.',
        'confidence_score' => 0.91,
        'is_selected' => true,
    ]);

    ResearchFinding::query()->create([
        'research_project_id' => $source->research_project_id,
        'research_source_id' => $source->id,
        'finding_type' => 'question',
        'finding_text' => 'Which channels underperform for conversion?',
        'confidence_score' => 0.83,
        'is_selected' => false,
    ]);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{}',
        json: [
            'executive_summary' => 'Research shows workflow speed and channel mix are the primary opportunities.',
            'key_insights' => ['Teams want faster workflows.'],
            'open_questions' => ['Which channels underperform for conversion?'],
            'brief_enrichment' => [
                'angles' => ['Speed to publish as differentiator'],
                'risks' => ['Over-optimization for one channel'],
                'keyword_clusters' => ['workflow automation'],
            ],
        ],
        usage: new LlmUsage(120, 70, 190),
        modelUsed: 'gpt-4.1-mini',
        providerName: 'openai',
        requestId: 'req-research-summary',
    ));

    app()->instance(LlmManager::class, $llm);

    $project = app(ResearchSummaryService::class)->persistSummary($source->project->fresh());

    expect((array) $project->summary)->not->toBeEmpty()
        ->and((int) data_get($project->summary, 'selected_finding_count'))->toBeGreaterThan(0)
        ->and((string) $project->human_summary)->toContain('workflow');
});

function makeResearchProcessingSource(
    string $url = 'https://example.com/report',
    ?string $contentText = null,
    ResearchSourceFetchStatus $fetchStatus = ResearchSourceFetchStatus::PENDING,
    array $meta = [],
): ResearchSource {
    $organization = Organization::query()->create([
        'name' => 'Research Processing Org',
        'slug' => 'research-processing-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Research Processing Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Research Processing Site',
        'site_url' => 'https://research-processing.example.com',
        'allowed_domains' => ['research-processing.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $project = ResearchProject::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Research processing project',
        'status' => 'draft',
        'config' => [
            'billing' => [
                'enabled' => false,
                'credits_per_source' => 0,
            ],
        ],
    ]);

    return ResearchSource::query()->create([
        'research_project_id' => $project->id,
        'source_type' => ResearchSourceType::URL,
        'source_classification' => 'web',
        'url' => $url,
        'content_text' => $contentText,
        'fetch_status' => $fetchStatus,
        'meta' => $meta,
    ]);
}
