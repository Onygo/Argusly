<?php

use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\OpportunitySignal;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityBridgeWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function agenticBridgeWriterFixture(): array
{
    $organization = Organization::query()->create([
        'name' => 'Agentic Bridge Writer Org',
        'slug' => 'agentic-bridge-writer-org-'.str()->random(8),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Agentic Bridge Writer Workspace',
        'display_name' => 'Agentic Bridge Writer Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Agentic Bridge Writer Site',
        'site_url' => 'https://agentic-bridge-writer.test',
        'base_url' => 'https://agentic-bridge-writer.test',
        'allowed_domains' => ['agentic-bridge-writer.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Bridge writer objective',
        'goal' => 'Write guarded canonical bridges',
        'locale' => 'en',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function agenticBridgeWriterOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Build missing AI visibility cluster',
        'type' => 'content_network',
        'priority_score' => 86,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'AI visibility',
            'summary' => 'Create a canonical content network opportunity for the AI visibility gap.',
            'recommendation' => 'Create the missing pillar and supporting articles.',
            'signals' => [
                'cluster_id' => 'cluster-writer-1',
                'cluster_name' => 'AI visibility',
                'topic_keyword' => 'AI visibility',
                'gap_type' => 'missing_pillar',
                'locale' => 'en',
            ],
            'score_explanation' => [
                'impact_score' => 88,
                'confidence_score' => 76,
                'effort_score' => 42,
            ],
        ],
    ], $overrides));
}

it('dry-runs bridge creation with the feature flag disabled', function (): void {
    [, , , $objective] = agenticBridgeWriterFixture();
    $legacy = agenticBridgeWriterOpportunity($objective);

    config(['features.mos_agentic_marketing_opportunity_bridge_writer' => false]);

    $result = app(AgenticOpportunityBridgeWriter::class)->write($legacy);

    expect($result->status)->toBe('would_create')
        ->and($result->dryRun)->toBeTrue()
        ->and(Opportunity::query()->count())->toBe(0)
        ->and(OpportunitySignal::query()->count())->toBe(0)
        ->and($legacy->fresh()->updated_at->equalTo($legacy->updated_at))->toBeTrue();
});

it('blocks apply unless the bridge writer feature flag is enabled', function (): void {
    [, , , $objective] = agenticBridgeWriterFixture();
    $legacy = agenticBridgeWriterOpportunity($objective);

    config(['features.mos_agentic_marketing_opportunity_bridge_writer' => false]);

    $result = app(AgenticOpportunityBridgeWriter::class)->write($legacy, apply: true);

    expect($result->status)->toBe('blocked')
        ->and($result->dryRun)->toBeFalse()
        ->and($result->reasons)->toContain('feature_flag_disabled')
        ->and(Opportunity::query()->count())->toBe(0);
});

it('creates a canonical opportunity bridge on flagged apply without touching signals or legacy execution', function (): void {
    [, $workspace, , $objective] = agenticBridgeWriterFixture();
    $legacy = agenticBridgeWriterOpportunity($objective);

    config(['features.mos_agentic_marketing_opportunity_bridge_writer' => true]);

    $result = app(AgenticOpportunityBridgeWriter::class)->write($legacy, apply: true, operatorContext: [
        'actor_id' => 'operator-1',
    ]);

    $canonical = $result->opportunity;

    expect($result->status)->toBe('created')
        ->and($result->dryRun)->toBeFalse()
        ->and($canonical)->toBeInstanceOf(Opportunity::class)
        ->and((string) $canonical->agentic_marketing_opportunity_id)->toBe((string) $legacy->id)
        ->and((string) $canonical->workspace_id)->toBe((string) $workspace->id)
        ->and($canonical->title)->toBe('Build missing AI visibility cluster')
        ->and($canonical->recommended_actions)->toContain('Create the missing pillar and supporting articles.')
        ->and($canonical->metadata['canonical_link_phase'])->toBe('3D')
        ->and($canonical->metadata['legacy_agentic_marketing_opportunity_id'])->toBe((string) $legacy->id)
        ->and($canonical->metadata['execution_continuity_note'])->toContain('Agentic actions and execution pipelines continue')
        ->and($canonical->source_signal_summary['source_scoped_dedupe_key'])->toBe($canonical->dedupe_hash)
        ->and(OpportunitySignal::query()->count())->toBe(0)
        ->and($legacy->fresh()->payload)->toBe($legacy->payload);
});

it('reports already-linked on repeated writes', function (): void {
    [, , , $objective] = agenticBridgeWriterFixture();
    $legacy = agenticBridgeWriterOpportunity($objective);

    config(['features.mos_agentic_marketing_opportunity_bridge_writer' => true]);

    $first = app(AgenticOpportunityBridgeWriter::class)->write($legacy, apply: true);
    $second = app(AgenticOpportunityBridgeWriter::class)->write($legacy, apply: true);

    expect($first->status)->toBe('created')
        ->and($second->status)->toBe('already_linked')
        ->and($second->canonicalId())->toBe($first->canonicalId())
        ->and(Opportunity::query()->count())->toBe(1);
});

it('blocks duplicate-risk rows instead of creating another canonical opportunity', function (): void {
    [, $workspace, , $objective] = agenticBridgeWriterFixture();
    $legacy = agenticBridgeWriterOpportunity($objective);
    $dryRun = app(AgenticOpportunityBridgeWriter::class)->write($legacy);

    Opportunity::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'agentic_marketing_opportunity_id' => null,
        'dedupe_hash' => $dryRun->eligibility->mappingResult->dedupeKey,
    ]);

    config(['features.mos_agentic_marketing_opportunity_bridge_writer' => true]);

    $result = app(AgenticOpportunityBridgeWriter::class)->write($legacy, apply: true);

    expect($result->status)->toBe('duplicate_risk')
        ->and($result->reasons)->toContain('canonical_opportunity_dedupe_match_without_bridge')
        ->and(Opportunity::query()->count())->toBe(1)
        ->and(Opportunity::query()->where('agentic_marketing_opportunity_id', $legacy->id)->exists())->toBeFalse();
});

it('runs the bridge command as a dry-run regardless of the feature flag', function (): void {
    [, $workspace, , $objective] = agenticBridgeWriterFixture();
    agenticBridgeWriterOpportunity($objective);

    config(['features.mos_agentic_marketing_opportunity_bridge_writer' => false]);

    $this->artisan('mos:link-agentic-opportunities', [
        '--workspace' => (string) $workspace->id,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Dry run only')
        ->expectsOutputToContain('inspected count: 1')
        ->expectsOutputToContain('would create count: 1');

    expect(Opportunity::query()->count())->toBe(0);
});

it('requires the feature flag for command apply', function (): void {
    [, $workspace, , $objective] = agenticBridgeWriterFixture();
    agenticBridgeWriterOpportunity($objective);

    config(['features.mos_agentic_marketing_opportunity_bridge_writer' => false]);

    $this->artisan('mos:link-agentic-opportunities', [
        '--workspace' => (string) $workspace->id,
        '--apply' => true,
    ])
        ->assertFailed()
        ->expectsOutputToContain('Apply blocked');

    expect(Opportunity::query()->count())->toBe(0);
});
