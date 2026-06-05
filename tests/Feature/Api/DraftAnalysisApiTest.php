<?php

use App\Jobs\AnalyzeDraftJob;
use App\Models\ClientSite;
use App\Models\Brief;
use App\Models\Draft;
use App\Models\DraftAnalysis;
use App\Models\Organization;
use App\Models\SiteToken;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeDraftAnalysisApiContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Draft Analysis API Org',
        'slug' => 'draft-analysis-api-' . Str::random(6),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft Analysis API Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Draft Analysis API Site',
        'site_url' => 'https://draft-analysis-api.example.com',
        'allowed_domains' => ['draft-analysis-api.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $token = 'pl_site_' . Str::random(48);
    SiteToken::query()->create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $token),
        'scopes' => ['drafts:read', 'drafts:write'],
        'revoked' => false,
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'draft',
        'source' => 'api',
        'title' => 'Draft analysis api brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 0,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Draft analysis api draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Draft analysis body.</p>',
    ]);

    return [$site, $token, $draft];
}

it('returns the latest draft analysis via the api', function () {
    Queue::fake();

    [$site, $token, $draft] = makeDraftAnalysisApiContext();

    DraftAnalysis::query()->create([
        'id' => (string) Str::uuid(),
        'draft_id' => $draft->id,
        'seo_score' => 88,
        'readability_score' => 81,
        'cta_score' => 70,
        'keyword_coverage' => 77,
        'entity_coverage' => 74,
        'analysis_model' => 'gpt-4.1-mini',
        'tokens_used' => 512,
        'internal_link_opportunities' => [],
        'suggestions' => [
            'summary' => [
                'headline' => 'Latest analysis',
                'overall_explanation' => 'Latest stored analysis.',
            ],
        ],
    ]);

    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'X-PublishLayer-Site' => parse_url((string) $site->site_url, PHP_URL_HOST),
    ];

    $this->withHeaders($headers)
        ->getJson('/api/v1/drafts/' . $draft->id . '/analysis')
        ->assertOk()
        ->assertJsonPath('item.seo_score', 88)
        ->assertJsonPath('item.analysis_model', 'gpt-4.1-mini');
});

it('queues draft analysis via the api', function () {
    Queue::fake();

    [$site, $token, $draft] = makeDraftAnalysisApiContext();

    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'X-PublishLayer-Site' => parse_url((string) $site->site_url, PHP_URL_HOST),
    ];

    $this->withHeaders($headers)
        ->postJson('/api/v1/drafts/' . $draft->id . '/analyze')
        ->assertStatus(202)
        ->assertJsonPath('ok', true);

    Queue::assertPushed(AnalyzeDraftJob::class, function (AnalyzeDraftJob $job) use ($draft): bool {
        return $job->draftId === (string) $draft->id && $job->force === true;
    });
});
