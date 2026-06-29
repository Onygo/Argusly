<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\RecommendedAction;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalReadService;
use App\Services\RecommendedActions\RecommendedActionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase2dReadContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 2D Read '.Str::random(6),
        'slug' => 'phase-2d-read-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 2D Read Workspace',
        'display_name' => 'Phase 2D Read Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 2D Site',
        'site_url' => 'https://phase-2d-read.test',
        'base_url' => 'https://phase-2d-read.test',
        'allowed_domains' => ['phase-2d-read.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$organization, $workspace, $site];
}

function phase2dReadContentOpportunity(array $overrides = []): ContentOpportunity
{
    [$organization, $workspace, $site] = phase2dReadContext();

    return ContentOpportunity::query()->create(array_merge([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => 'implementation_guide',
        'status' => ContentOpportunity::STATUS_OPEN,
        'freshness_status' => 'fresh',
        'title' => 'Legacy AI visibility guide',
        'reasoning' => 'Legacy reasoning stays available.',
        'why_this_matters' => 'Legacy why this matters.',
        'why_now' => 'Legacy timing.',
        'funnel_stage' => 'consideration',
        'primary_search_intent' => 'implementation',
        'angle' => 'Legacy operator angle.',
        'expected_impact' => 'high',
        'confidence_score' => 64,
        'urgency_score' => 61,
        'business_value_score' => 67,
        'priority_score' => 70,
        'related_entities' => ['AI visibility'],
        'suggested_cta' => 'Create the legacy brief',
        'suggested_schema' => 'HowTo',
        'source_signals' => [['type' => 'legacy_signal']],
        'normalized_payload' => ['candidate' => ['topic' => 'legacy ai visibility']],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

it('prefers linked canonical opportunity fields while preserving legacy ids and provenance', function (): void {
    $contentOpportunity = phase2dReadContentOpportunity();
    $canonical = Opportunity::factory()->create([
        'organization_id' => $contentOpportunity->organization_id,
        'workspace_id' => $contentOpportunity->workspace_id,
        'client_site_id' => $contentOpportunity->client_site_id,
        'content_opportunity_id' => $contentOpportunity->id,
        'category' => OpportunityCategory::AI_VISIBILITY_OPPORTUNITY,
        'status' => OpportunityStatus::OPEN,
        'title' => 'Canonical AI visibility playbook',
        'summary' => 'Canonical summary.',
        'priority_score' => 91,
        'confidence_score' => 88,
        'impact_score' => 84,
        'urgency_score' => 79,
        'effort_score' => 35,
        'score_breakdown' => ['business_value' => 93],
        'recommended_actions' => [['title' => 'Use canonical action']],
        'evidence' => [['type' => 'canonical_evidence']],
    ]);

    $read = app(ContentOpportunityCanonicalReadService::class)->read($contentOpportunity);

    expect($read->legacyContentOpportunityId)->toBe($contentOpportunity->id)
        ->and($read->canonicalOpportunityId)->toBe($canonical->id)
        ->and($read->title)->toBe('Canonical AI visibility playbook')
        ->and($read->type)->toBe('implementation_guide')
        ->and($read->priority)->toBe(91.0)
        ->and($read->confidence)->toBe(88.0)
        ->and($read->impact)->toBe(84.0)
        ->and($read->effort)->toBe(35.0)
        ->and($read->urgency)->toBe(79.0)
        ->and($read->businessValue)->toBe(93.0)
        ->and($read->recommendedActions[0])->toMatchArray(['title' => 'Use canonical action'])
        ->and($read->evidence[0])->toMatchArray(['type' => 'canonical_evidence'])
        ->and($read->provenance)->toMatchArray([
            'title' => 'canonical',
            'type' => 'legacy',
            'status' => 'legacy',
            'priority' => 'canonical',
            'business_value' => 'canonical',
        ]);
});

it('falls back to legacy fields when no canonical link exists', function (): void {
    $contentOpportunity = phase2dReadContentOpportunity();

    $read = app(ContentOpportunityCanonicalReadService::class)->read($contentOpportunity);

    expect($read->canonicalOpportunityId)->toBeNull()
        ->and($read->title)->toBe('Legacy AI visibility guide')
        ->and($read->type)->toBe('implementation_guide')
        ->and($read->priority)->toBe(70.0)
        ->and($read->businessValue)->toBe(67.0)
        ->and($read->recommendedActions)->toContain('Create the legacy brief', 'HowTo')
        ->and($read->provenance)->toMatchArray([
            'title' => 'legacy',
            'type' => 'legacy',
            'priority' => 'legacy',
            'business_value' => 'legacy',
        ]);
});

it('does not mutate data, create briefs, or change lifecycle status while reading', function (): void {
    $contentOpportunity = phase2dReadContentOpportunity(['status' => ContentOpportunity::STATUS_OPEN]);
    Opportunity::factory()->create([
        'organization_id' => $contentOpportunity->organization_id,
        'workspace_id' => $contentOpportunity->workspace_id,
        'client_site_id' => $contentOpportunity->client_site_id,
        'content_opportunity_id' => $contentOpportunity->id,
    ]);
    $legacyUpdatedAt = $contentOpportunity->updated_at?->toIso8601String();
    $canonicalUpdatedAt = Opportunity::query()->firstOrFail()->updated_at?->toIso8601String();

    app(ContentOpportunityCanonicalReadService::class)->read($contentOpportunity);

    expect($contentOpportunity->refresh()->status)->toBe(ContentOpportunity::STATUS_OPEN)
        ->and($contentOpportunity->updated_at?->toIso8601String())->toBe($legacyUpdatedAt)
        ->and(Opportunity::query()->firstOrFail()->updated_at?->toIso8601String())->toBe($canonicalUpdatedAt)
        ->and(Brief::query()->count())->toBe(0);
});

it('keeps recommended actions legacy-sourced to avoid duplicate canonical action semantics', function (): void {
    $contentOpportunity = phase2dReadContentOpportunity();
    Opportunity::factory()->create([
        'organization_id' => $contentOpportunity->organization_id,
        'workspace_id' => $contentOpportunity->workspace_id,
        'client_site_id' => $contentOpportunity->client_site_id,
        'content_opportunity_id' => $contentOpportunity->id,
        'title' => 'Canonical linked action source',
    ]);

    $first = app(RecommendedActionEngine::class)->upsertFromSource($contentOpportunity);
    $second = app(RecommendedActionEngine::class)->upsertFromSource($contentOpportunity->refresh());

    expect($second->id)->toBe($first->id)
        ->and(RecommendedAction::query()->count())->toBe(1)
        ->and($second->source_type)->toBe(ContentOpportunity::class)
        ->and($second->source_id)->toBe($contentOpportunity->id);
});
