<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\ClientSite;
use App\Models\ContentOpportunity;
use App\Models\ContentOpportunityRun;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\ContentOpportunityRunCanonicalReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase2gContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 2G '.Str::random(6),
        'slug' => 'phase-2g-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 2G Workspace',
        'display_name' => 'Phase 2G Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 2G Site',
        'site_url' => 'https://phase-2g.test',
        'base_url' => 'https://phase-2g.test',
        'allowed_domains' => ['phase-2g.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$organization, $workspace, $site];
}

function phase2gRun(?Workspace $workspace = null, ?ClientSite $site = null, array $overrides = []): ContentOpportunityRun
{
    if (! $workspace || ! $site) {
        [, $workspace, $site] = phase2gContext();
    }

    return ContentOpportunityRun::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'status' => 'completed',
        'source_type' => 'agentic_marketing',
        'input' => ['source_type' => 'test'],
        'result' => ['opportunity_ids' => []],
        'candidates_count' => 0,
        'created_count' => 0,
        'refreshed_count' => 0,
        'started_at' => now(),
        'finished_at' => now(),
    ], $overrides));
}

function phase2gContentOpportunity(ContentOpportunityRun $run, array $overrides = []): ContentOpportunity
{
    return ContentOpportunity::query()->create(array_merge([
        'organization_id' => $run->organization_id,
        'workspace_id' => $run->workspace_id,
        'client_site_id' => $run->client_site_id,
        'content_opportunity_run_id' => $run->id,
        'type' => 'implementation_guide',
        'status' => ContentOpportunity::STATUS_OPEN,
        'freshness_status' => 'fresh',
        'title' => 'Phase 2G canonical reference guide',
        'reasoning' => 'Run metrics should be able to show canonical references.',
        'angle' => 'Keep run ownership legacy while reporting links.',
        'expected_impact' => 'high',
        'confidence_score' => 70,
        'urgency_score' => 65,
        'business_value_score' => 80,
        'priority_score' => 90,
        'source_signals' => [['type' => 'phase_2g']],
        'normalized_payload' => ['candidate' => ['topic' => 'run canonical references']],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function phase2gCanonicalOpportunity(ContentOpportunity $opportunity, array $overrides = []): Opportunity
{
    return Opportunity::factory()->create(array_merge([
        'organization_id' => $opportunity->organization_id,
        'workspace_id' => $opportunity->workspace_id,
        'client_site_id' => $opportunity->client_site_id,
        'content_opportunity_id' => $opportunity->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical run reference guide',
        'topic' => 'run canonical references',
        'summary' => 'Canonical rows can be referenced by run metrics.',
        'dedupe_hash' => $opportunity->dedupe_hash,
    ], $overrides));
}

it('reports linked and unlinked canonical references for a run without mutating records', function (): void {
    [, $workspace, $site] = phase2gContext();
    $run = phase2gRun($workspace, $site, [
        'candidates_count' => 2,
        'created_count' => 2,
        'result' => ['opportunity_ids' => []],
    ]);
    $linked = phase2gContentOpportunity($run, ['title' => 'Linked run candidate']);
    $unlinked = phase2gContentOpportunity($run, ['title' => 'Unlinked run candidate']);
    $canonical = phase2gCanonicalOpportunity($linked);
    $run->forceFill(['result' => ['opportunity_ids' => [$linked->id, $unlinked->id]]])->save();
    $runUpdatedAt = $run->updated_at?->toIso8601String();
    $legacyUpdatedAt = $linked->updated_at?->toIso8601String();

    $summary = app(ContentOpportunityRunCanonicalReferenceService::class)->inspect($run->refresh());

    expect($summary['legacy_opportunity_count'])->toBe(2)
        ->and($summary['linked_candidate_count'])->toBe(1)
        ->and($summary['unlinked_candidate_count'])->toBe(1)
        ->and($summary['linked_canonical_opportunity_count'])->toBe(1)
        ->and($summary['missing_links'])->toContain((string) $unlinked->id)
        ->and($summary['canonical_opportunity_ids_by_legacy_status'][ContentOpportunity::STATUS_OPEN]['canonical_opportunity_ids'])->toContain((string) $canonical->id)
        ->and($run->refresh()->updated_at?->toIso8601String())->toBe($runUpdatedAt)
        ->and($linked->refresh()->updated_at?->toIso8601String())->toBe($legacyUpdatedAt);
});

it('detects duplicate canonical link risks for one legacy candidate', function (): void {
    $run = phase2gRun();
    $legacy = phase2gContentOpportunity($run);
    $first = phase2gCanonicalOpportunity($legacy, ['title' => 'First canonical link']);
    $second = phase2gCanonicalOpportunity($legacy, ['title' => 'Second canonical link', 'dedupe_hash' => hash('sha256', 'second-'.$legacy->id)]);

    $summary = app(ContentOpportunityRunCanonicalReferenceService::class)->inspect($run);

    expect($summary['duplicate_link_risk_count'])->toBe(1)
        ->and($summary['duplicate_link_risks'][0]['legacy_content_opportunity_id'])->toBe((string) $legacy->id)
        ->and($summary['duplicate_link_risks'][0]['canonical_opportunity_ids'])->toContain((string) $first->id, (string) $second->id);
});

it('keeps the diagnostics command read-only by default', function (): void {
    $run = phase2gRun();
    $legacy = phase2gContentOpportunity($run);
    phase2gCanonicalOpportunity($legacy);
    $runResult = $run->result;
    $runUpdatedAt = $run->updated_at?->toIso8601String();

    $this->artisan('mos:inspect-content-opportunity-run-links', [
        '--run-id' => $run->id,
        '--limit' => 5,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only run link inspection')
        ->expectsOutputToContain('linked canonical opportunities');

    expect($run->refresh()->result)->toBe($runResult)
        ->and($run->updated_at?->toIso8601String())->toBe($runUpdatedAt)
        ->and($legacy->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN);
});

it('writes a canonical reference summary only when requested', function (): void {
    $run = phase2gRun();
    $legacy = phase2gContentOpportunity($run);
    $canonical = phase2gCanonicalOpportunity($legacy);

    $this->artisan('mos:inspect-content-opportunity-run-links', [
        '--run-id' => $run->id,
        '--write-summary' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Writing canonical reference summaries only')
        ->expectsOutputToContain('summaries written');

    $summary = $run->refresh()->result['canonical_reference_summary'];

    expect($summary['schema'])->toBe('content_opportunity_run_canonical_references.v1')
        ->and($summary['linked_candidate_count'])->toBe(1)
        ->and($summary['unlinked_candidate_count'])->toBe(0)
        ->and($summary['canonical_opportunity_id_samples'])->toContain((string) $canonical->id)
        ->and($run->candidates_count)->toBe(0)
        ->and($run->created_count)->toBe(0)
        ->and($run->refreshed_count)->toBe(0)
        ->and($legacy->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN);
});
