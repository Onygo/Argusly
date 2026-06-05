<?php

use App\Models\ClientSite;
use App\Models\LlmAuthorityEntityCandidate;
use App\Models\LlmAuthorityLearning;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\Organization;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\LlmTracking\LlmAuthorityCandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function llmCandidateTestSite(): ClientSite
{
    $organization = Organization::query()->create([
        'name' => 'Acme',
        'slug' => 'acme-' . Str::random(6),
        'status' => 'approved',
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'name' => 'Acme Workspace',
    ]);

    return ClientSite::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Acme Site',
        'site_url' => 'https://publishlayer.com',
        'base_url' => 'https://publishlayer.com',
        'allowed_domains' => ['publishlayer.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);
}

it('creates candidates from run authority entities and extracts learnings', function () {
    $site = llmCandidateTestSite();
    $query = LlmTrackingQuery::query()->create([
        'workspace_id' => $site->workspace_id,
        'client_site_id' => $site->id,
        'name' => 'AI visibility',
        'query_text' => 'best AI content tools for SEO',
        'target_brand' => 'PublishLayer',
        'brand_terms' => ['PublishLayer'],
        'competitor_terms' => [],
        'target_urls' => ['https://publishlayer.com'],
        'locale' => 'en',
        'frequency' => 'daily',
        'is_active' => true,
    ]);

    $run = LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $query->id,
        'run_at' => now(),
        'provider' => 'anthropic',
        'model' => 'claude-sonnet',
        'status' => 'succeeded',
        'authority_entities' => [
            [
                'brand_name' => 'Ahrefs',
                'normalized_name' => 'ahrefs',
                'entity_category' => 'benchmark',
                'mention_count' => 2,
                'rank' => 1,
                'source_urls' => ['https://ahrefs.com/brand-radar'],
                'confidence_score' => 0.85,
                'same_category' => true,
                'reason' => 'Mentioned as a benchmark entity.',
                'context_snippets' => ['Ahrefs is associated with Brand Radar and AI visibility.'],
            ],
        ],
    ]);

    app(LlmAuthorityCandidateService::class)->recordRun($run);

    $candidate = LlmAuthorityEntityCandidate::query()->first();

    expect($candidate)->not->toBeNull()
        ->and($candidate->brand_name)->toBe('Ahrefs')
        ->and($candidate->mention_count)->toBe(2)
        ->and(data_get($candidate->provider_breakdown, 'anthropic.mention_count'))->toBe(2)
        ->and(LlmAuthorityLearning::query()->where('llm_authority_entity_candidate_id', $candidate->id)->count())->toBeGreaterThan(0);
});

it('accepts candidates into the site competitor list without duplicating competitors', function () {
    $site = llmCandidateTestSite();
    $candidate = LlmAuthorityEntityCandidate::query()->create([
        'workspace_id' => $site->workspace_id,
        'client_site_id' => $site->id,
        'brand_name' => 'Semrush',
        'normalized_name' => 'semrush',
        'entity_category' => 'competitor',
        'mention_count' => 3,
        'latest_rank' => 1,
        'source_urls' => ['https://www.semrush.com/features/ai-visibility'],
        'provider_breakdown' => ['openai' => ['mention_count' => 3]],
        'confidence_score' => 0.9,
        'status' => 'candidate',
    ]);

    $service = app(LlmAuthorityCandidateService::class);
    $first = $service->accept($candidate);
    $second = $service->accept($candidate->refresh());

    expect($first->id)->toBe($second->id)
        ->and(SiteCompetitor::query()->where('client_site_id', $site->id)->where('name', 'Semrush')->count())->toBe(1)
        ->and($candidate->refresh()->status)->toBe('accepted');
});
