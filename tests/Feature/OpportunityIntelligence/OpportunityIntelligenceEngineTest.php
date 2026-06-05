<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\OpportunityIntelligence\OpportunityIntelligenceEngine;
use App\Services\OpportunityIntelligence\OpportunitySignalIngestor;
use App\Services\OpportunityIntelligence\OpportunitySignalPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('ingests explainable signals and creates scored recommended opportunities', function (): void {
    $organization = Organization::query()->create([
        'name' => 'Opportunity Intelligence Org',
        'slug' => 'opportunity-intelligence-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Opportunity Intelligence Workspace',
    ]);

    $ingestor = app(OpportunitySignalIngestor::class);

    $ingestor->ingest($workspace, new OpportunitySignalPayload(
        source: OpportunitySignalSource::SEARCH_TRENDS,
        category: OpportunityCategory::TREND_OPPORTUNITY,
        topic: 'agentic marketing workflows',
        entity: 'Agentic Marketing',
        signalStrength: 84,
        confidence: 78,
        metrics: ['query_growth' => 0.42],
        evidence: [['source' => 'search_trends', 'summary' => 'Query growth increased 42%.']],
    ));

    $ingestor->ingest($workspace, new OpportunitySignalPayload(
        source: OpportunitySignalSource::ENGAGEMENT_ANALYTICS,
        category: OpportunityCategory::TREND_OPPORTUNITY,
        topic: 'agentic marketing workflows',
        entity: 'Agentic Marketing',
        signalStrength: 72,
        confidence: 74,
        metrics: ['linkedin_engagement_rate' => 0.08],
        evidence: [['source' => 'linkedin', 'summary' => 'Related social posts are gaining engagement.']],
    ));

    $result = app(OpportunityIntelligenceEngine::class)->run($workspace);

    $opportunity = Opportunity::query()->where('workspace_id', $workspace->id)->firstOrFail();

    expect($result['created'])->toBe(1)
        ->and($opportunity->category)->toBe(OpportunityCategory::TREND_OPPORTUNITY)
        ->and($opportunity->priority_score)->toBeGreaterThan(65)
        ->and($opportunity->confidence_score)->toBeGreaterThan(70)
        ->and($opportunity->recommended_actions)->not->toBeEmpty()
        ->and($opportunity->signals()->count())->toBe(2);
});
