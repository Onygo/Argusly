<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3gReadFixture(): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3G Read '.Str::random(6),
        'slug' => 'phase-3g-read-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3G Read Workspace',
        'display_name' => 'Phase 3G Read Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3G Site',
        'site_url' => 'https://phase-3g-read.test',
        'base_url' => 'https://phase-3g-read.test',
        'allowed_domains' => ['phase-3g-read.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3G objective',
        'goal' => 'Inspect canonical dual-read context',
        'locale' => 'en',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3gReadOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Legacy content network gap',
        'type' => 'content_network',
        'priority_score' => 73,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Legacy AI visibility',
            'summary' => 'Legacy summary.',
            'recommendation' => 'Create the legacy cluster.',
            'signals' => [
                'cluster_id' => 'cluster-phase-3g',
                'cluster_name' => 'Legacy AI visibility',
                'topic_keyword' => 'Legacy AI visibility',
                'gap_type' => 'missing_pillar',
                'locale' => 'en',
            ],
            'score_explanation' => [
                'summary' => 'Legacy score summary.',
                'impact_score' => 71,
                'confidence_score' => 62,
                'effort_score' => 44,
            ],
        ],
    ], $overrides));
}

it('prefers linked canonical strategic fields while preserving legacy execution identity', function (): void {
    [, $workspace, $site, $objective] = phase3gReadFixture();
    $legacy = phase3gReadOpportunity($objective);
    $canonical = Opportunity::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'agentic_marketing_opportunity_id' => $legacy->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical content network opportunity',
        'summary' => 'Canonical summary.',
        'priority_score' => 91,
        'confidence_score' => 88,
        'impact_score' => 86,
        'effort_score' => 31,
        'recommended_actions' => [['title' => 'Use canonical action']],
        'evidence' => [['type' => 'canonical_evidence']],
        'source_signal_summary' => ['detector_key' => 'content_network_gaps'],
    ]);

    $read = app(AgenticOpportunityCanonicalReadService::class)->read($legacy);

    expect($read->legacyAgenticOpportunityId)->toBe($legacy->id)
        ->and($read->canonicalOpportunityId)->toBe($canonical->id)
        ->and($read->objectiveId)->toBe($objective->id)
        ->and($read->workspaceId)->toBe($workspace->id)
        ->and($read->siteId)->toBe($site->id)
        ->and($read->title)->toBe('Canonical content network opportunity')
        ->and($read->summary)->toBe('Canonical summary.')
        ->and($read->status)->toBe('open')
        ->and($read->agenticType)->toBe('content_network')
        ->and($read->priorityScore)->toBe(91.0)
        ->and($read->confidenceScore)->toBe(88.0)
        ->and($read->impactScore)->toBe(86.0)
        ->and($read->effortScore)->toBe(31.0)
        ->and($read->recommendedActions[0])->toMatchArray(['title' => 'Use canonical action'])
        ->and($read->evidence[0])->toMatchArray(['type' => 'canonical_evidence'])
        ->and($read->provenance)->toMatchArray([
            'title' => 'canonical',
            'summary' => 'canonical',
            'status' => 'legacy',
            'agentic_type' => 'legacy',
            'priority_score' => 'canonical',
        ])
        ->and($read->migrationReadiness['legacy_execution_authoritative'])->toBeTrue();
});

it('falls back to legacy fields when no safe canonical bridge exists', function (): void {
    [, , , $objective] = phase3gReadFixture();
    $legacy = phase3gReadOpportunity($objective);

    $read = app(AgenticOpportunityCanonicalReadService::class)->read($legacy);

    expect($read->canonicalOpportunityId)->toBeNull()
        ->and($read->title)->toBe('Legacy content network gap')
        ->and($read->summary)->toBe('Legacy summary.')
        ->and($read->priorityScore)->toBe(73.0)
        ->and($read->confidenceScore)->toBe(62.0)
        ->and($read->recommendedActions)->toContain('Create the legacy cluster.')
        ->and($read->provenance)->toMatchArray([
            'title' => 'legacy',
            'summary' => 'legacy',
            'priority_score' => 'legacy',
            'recommended_actions' => 'legacy',
        ]);
});

it('blocks canonical enrichment when multiple canonical opportunities point at the same legacy row', function (): void {
    [, $workspace, $site, $objective] = phase3gReadFixture();
    $legacy = phase3gReadOpportunity($objective);

    Opportunity::factory()->count(2)->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'agentic_marketing_opportunity_id' => $legacy->id,
    ]);

    $read = app(AgenticOpportunityCanonicalReadService::class)->read($legacy);

    expect($read->canonicalOpportunityId)->toBeNull()
        ->and($read->blockedReasons)->toContain('multiple_canonical_opportunities_linked_to_agentic_row')
        ->and($read->migrationReadiness['blocked'])->toBeTrue()
        ->and($read->provenance['title'])->toBe('legacy');
});

it('reports canonical read model diagnostics without writing data', function (): void {
    [, $workspace, $site, $objective] = phase3gReadFixture();
    $legacy = phase3gReadOpportunity($objective);
    Opportunity::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'agentic_marketing_opportunity_id' => $legacy->id,
        'title' => 'Canonical diagnostic title',
        'priority_score' => 94,
    ]);

    $legacyUpdatedAt = $legacy->updated_at?->toIso8601String();
    $canonicalUpdatedAt = Opportunity::query()->firstOrFail()->updated_at?->toIso8601String();

    $this->artisan('mos:inspect-agentic-canonical-read-model', [
        '--workspace' => (string) $workspace->id,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('total inspected count: 1')
        ->expectsOutputToContain('linked canonical count: 1')
        ->expectsOutputToContain('canonical enriched count: 1')
        ->expectsOutputToContain('field provenance samples:');

    expect($legacy->refresh()->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and(Opportunity::query()->firstOrFail()->updated_at?->toIso8601String())->toBe($canonicalUpdatedAt);
});
