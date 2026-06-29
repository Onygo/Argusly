<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalLinkService;
use App\Services\Mos\Opportunity\Providers\ContentOpportunityProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function contentCanonicalContext(string $slug = 'content-canonical-link'): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Canonical '.$slug,
        'slug' => $slug,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Argusly',
        'display_name' => 'Argusly',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Argusly Site',
        'site_url' => 'https://argusly.test',
        'base_url' => 'https://argusly.test',
        'allowed_domains' => ['argusly.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$organization, $workspace, $site];
}

function contentCanonicalOpportunity(array $overrides = []): ContentOpportunity
{
    [$organization, $workspace, $site] = contentCanonicalContext('content-link-'.str()->random(8));

    return ContentOpportunity::query()->create(array_merge([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => 'content_gap',
        'status' => ContentOpportunity::STATUS_OPEN,
        'title' => 'Build comparison content for AI visibility',
        'reasoning' => 'Competitors own comparison demand.',
        'why_this_matters' => 'This can recover decision-stage demand.',
        'why_now' => 'Competitor pressure is active now.',
        'expected_impact' => 'high',
        'confidence_score' => 74,
        'urgency_score' => 66,
        'business_value_score' => 79,
        'priority_score' => 88,
        'suggested_cta' => 'Create a comparison brief',
        'suggested_schema' => 'Article',
        'source_signals' => [
            ['source' => 'competitor-gap', 'url' => 'https://competitor.test/ai-visibility'],
        ],
        'query_intent_payload' => ['primary_intent' => 'comparison'],
        'normalized_payload' => [
            'candidate' => ['topic' => 'AI visibility comparison'],
            'score' => ['expected_impact' => 'high'],
        ],
        'dedupe_hash' => hash('sha256', 'content-link-'.str()->random(16)),
        'first_seen_at' => now()->subDay(),
        'last_seen_at' => now(),
    ], $overrides));
}

it('documents the content opportunity consumer audit', function (): void {
    expect(file_exists(base_path('docs/mos/content-opportunity-consumer-audit.md')))->toBeTrue()
        ->and(file_get_contents(base_path('docs/mos/content-opportunity-consumer-audit.md')))
        ->toContain('AppContentOpportunityController')
        ->toContain('CampaignClusterInputBuilder')
        ->toContain('GrowthProgramOrchestrator')
        ->toContain('SharedMarketingContextBuilder');
});

it('keeps the link command dry run by default', function (): void {
    $opportunity = contentCanonicalOpportunity();

    $this->artisan('mos:link-content-opportunities', [
        '--workspace' => $opportunity->workspace_id,
    ])->assertSuccessful();

    expect(Opportunity::query()->count())->toBe(0);
});

it('applies content opportunity canonical links with preserved bridge evidence and actions', function (): void {
    $opportunity = contentCanonicalOpportunity();

    $this->artisan('mos:link-content-opportunities', [
        '--apply' => true,
        '--source-id' => $opportunity->id,
    ])->assertSuccessful();

    $canonical = Opportunity::query()->firstOrFail();

    expect($canonical->content_opportunity_id)->toBe($opportunity->id)
        ->and($canonical->workspace_id)->toBe($opportunity->workspace_id)
        ->and($canonical->client_site_id)->toBe($opportunity->client_site_id)
        ->and($canonical->category)->toBe(OpportunityCategory::CONTENT_GAP)
        ->and($canonical->status)->toBe(OpportunityStatus::OPEN)
        ->and((float) $canonical->priority_score)->toBe(88.0)
        ->and((float) $canonical->confidence_score)->toBe(74.0)
        ->and((float) $canonical->impact_score)->toBe(80.0)
        ->and($canonical->recommended_actions)->toContain('Create a comparison brief', 'Article')
        ->and($canonical->evidence[0])->toMatchArray(['source' => 'competitor-gap'])
        ->and($canonical->evidence[1])->toMatchArray([
            'type' => 'legacy_content_opportunity',
            'source_model' => ContentOpportunity::class,
            'source_id' => (string) $opportunity->id,
        ])
        ->and($canonical->source_signal_summary)->toMatchArray([
            'source' => 'legacy-content-opportunities',
            'source_model' => ContentOpportunity::class,
            'source_id' => (string) $opportunity->id,
            'content_opportunity_id' => (string) $opportunity->id,
        ])
        ->and($canonical->metadata)->toMatchArray([
            'canonical_link_phase' => '2C',
            'legacy_status' => ContentOpportunity::STATUS_OPEN,
            'legacy_expected_impact' => 'high',
        ]);
});

it('does not duplicate an already linked content opportunity', function (): void {
    $opportunity = contentCanonicalOpportunity();
    $linker = app(ContentOpportunityCanonicalLinkService::class);

    $first = $linker->link($opportunity, apply: true);
    $second = $linker->link($opportunity->refresh(), apply: true);

    expect($first->status)->toBe('created')
        ->and($second->status)->toBe('linked')
        ->and($second->opportunity?->id)->toBe($first->opportunity?->id)
        ->and(Opportunity::query()->where('workspace_id', $opportunity->workspace_id)->count())->toBe(1);
});

it('uses the stable dedupe key to link an existing canonical opportunity', function (): void {
    $opportunity = contentCanonicalOpportunity();
    $linker = app(ContentOpportunityCanonicalLinkService::class);
    $candidate = app(ContentOpportunityProvider::class)->toCanonicalOpportunity($opportunity);

    $existing = Opportunity::factory()->create([
        'organization_id' => $opportunity->organization_id,
        'workspace_id' => $opportunity->workspace_id,
        'client_site_id' => $opportunity->client_site_id,
        'content_opportunity_id' => null,
        'dedupe_hash' => $linker->dedupeHash($candidate),
    ]);

    $result = $linker->link($opportunity, apply: true);

    expect($result->status)->toBe('linked')
        ->and($result->opportunity?->id)->toBe($existing->id)
        ->and($existing->refresh()->content_opportunity_id)->toBe($opportunity->id)
        ->and(Opportunity::query()->where('workspace_id', $opportunity->workspace_id)->count())->toBe(1);
});

it('reports duplicates when dedupe points at another content opportunity', function (): void {
    $opportunity = contentCanonicalOpportunity();
    $other = contentCanonicalOpportunity([
        'organization_id' => $opportunity->organization_id,
        'workspace_id' => $opportunity->workspace_id,
        'client_site_id' => $opportunity->client_site_id,
    ]);
    $linker = app(ContentOpportunityCanonicalLinkService::class);
    $candidate = app(ContentOpportunityProvider::class)->toCanonicalOpportunity($opportunity);

    Opportunity::factory()->create([
        'organization_id' => $opportunity->organization_id,
        'workspace_id' => $opportunity->workspace_id,
        'client_site_id' => $opportunity->client_site_id,
        'content_opportunity_id' => $other->id,
        'dedupe_hash' => $linker->dedupeHash($candidate),
    ]);

    $result = $linker->link($opportunity, apply: true);

    expect($result->status)->toBe('duplicate')
        ->and($result->reasons)->toContain('dedupe_hash_linked_to_another_content_opportunity')
        ->and(Opportunity::query()->where('workspace_id', $opportunity->workspace_id)->count())->toBe(1);
});

it('skips missing required canonical context', function (): void {
    $opportunity = contentCanonicalOpportunity([
        'title' => '',
        'dedupe_hash' => '',
        'reasoning' => null,
        'why_this_matters' => null,
        'source_signals' => null,
    ]);

    $result = app(ContentOpportunityCanonicalLinkService::class)->link($opportunity, apply: true);

    expect($result->status)->toBe('skipped')
        ->and($result->reasons)->toContain('title', 'dedupe_key', 'evidence_or_reasoning')
        ->and(Opportunity::query()->count())->toBe(0);
});

it('leaves existing content opportunity brief flow unchanged by canonical linking', function (): void {
    $opportunity = contentCanonicalOpportunity();

    app(ContentOpportunityCanonicalLinkService::class)->link($opportunity, apply: true);

    expect(Brief::query()->count())->toBe(0)
        ->and($opportunity->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN);
});
