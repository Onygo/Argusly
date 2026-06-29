<?php

use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\OpportunitySignal;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function agenticDetectorMappingFixture(): array
{
    $organization = Organization::query()->create([
        'name' => 'Agentic Mapping Org',
        'slug' => 'agentic-mapping-org',
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Agentic Mapping Workspace',
        'display_name' => 'Agentic Mapping Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Agentic Mapping Site',
        'site_url' => 'https://agentic-mapping.test',
        'base_url' => 'https://agentic-mapping.test',
        'allowed_domains' => ['agentic-mapping.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'AI visibility objective',
        'goal' => 'Grow AI visibility',
        'locale' => 'en',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

it('reports Agentic detector mapping diagnostics without creating canonical records', function (): void {
    [, $workspace, $site, $objective] = agenticDetectorMappingFixture();

    AgenticMarketingOpportunity::query()->create([
        'objective_id' => $objective->id,
        'title' => 'Improve AI visibility',
        'type' => 'ai_visibility',
        'priority_score' => 81,
        'status' => 'open',
        'payload' => [
            'detector' => 'ai_visibility_gaps',
            'client_site_id' => (string) $site->id,
            'topic' => 'AI visibility',
            'signals' => [
                'topic_keyword' => 'AI visibility',
                'ai_visibility_score' => 42,
            ],
            'score_explanation' => [
                'impact_score' => 84,
                'confidence_score' => 72,
                'effort_score' => 44,
            ],
        ],
    ]);

    $this->artisan('mos:map-agentic-detector-outputs', [
        '--workspace' => (string) $workspace->id,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only Agentic detector output mapping')
        ->expectsOutputToContain('signal-capable count: 1')
        ->expectsOutputToContain('opportunity-capable count: 0')
        ->expectsOutputToContain('ai_visibility_gaps');

    expect(Opportunity::query()->count())->toBe(0)
        ->and(OpportunitySignal::query()->count())->toBe(0);
});

it('can report deterministic sample classifications without running detectors', function (): void {
    $this->artisan('mos:map-agentic-detector-outputs', [
        '--sample' => true,
        '--detector' => 'content_network_gaps',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Live detectors are not run')
        ->expectsOutputToContain('content_network_gaps')
        ->expectsOutputToContain('opportunity-capable count: 1');

    expect(Opportunity::query()->count())->toBe(0)
        ->and(OpportunitySignal::query()->count())->toBe(0);
});
