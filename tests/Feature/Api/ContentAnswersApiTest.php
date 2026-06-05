<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\SiteToken;
use App\Models\StructuredAnswerBlock;
use App\Models\Workspace;
use App\Services\Api\ApiScopes;
use App\Services\Integrations\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('returns structured answers and aeo score for workspace api clients', function () {
    [, $content, , $apiHeaders] = makeContentAnswersApiContext();
    $expectedScore = $content->fresh()->aeo_score;

    $this->withHeaders($apiHeaders)
        ->getJson('/api/v1/content/' . $content->id . '/answers')
        ->assertOk()
        ->assertJsonPath('aeo_score', $expectedScore)
        ->assertJsonPath('answers.0.question', 'What is AEO?')
        ->assertJsonPath('answers.0.entities.0', 'PublishLayer');
});

it('returns site scoped answer payloads through markdown delivery routes', function () {
    [$site, $content, $siteHeaders] = makeContentAnswersApiContext();
    $expectedScore = $content->fresh()->aeo_score;

    $this->withHeaders($siteHeaders)
        ->getJson('/api/sites/' . $site->id . '/content/' . $content->id . '/answers')
        ->assertOk()
        ->assertJsonPath('aeo_score', $expectedScore)
        ->assertJsonPath('answers.0.question', 'What is AEO?');
});

function makeContentAnswersApiContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Answers API Org ' . Str::random(4),
        'slug' => 'answers-api-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Answers Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en'],
    ]);

    $site = ClientSite::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Answers Site',
        'site_url' => 'https://answers-api.example.com',
        'base_url' => 'https://answers-api.example.com',
        'allowed_domains' => ['answers-api.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plainSiteToken = 'pl_site_' . Str::random(48);
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'workspace_id' => $workspace->id,
        'token_hash' => hash('sha256', $plainSiteToken),
        'scopes' => [ApiScopes::DRAFTS_READ],
        'abilities' => [ApiScopes::DRAFTS_READ],
        'revoked' => false,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'AEO answers guide',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'source' => 'api',
        'publish_status' => 'published',
        'published_url' => 'https://answers-api.example.com/blog/aeo-answers-guide',
        'publish_url_key' => 'aeo-answers-guide',
        'canonical_url_key' => 'aeo-answers-guide',
        'external_key' => 'aeo-answers-guide',
        'aeo_score' => 84,
        'aeo_breakdown' => ['breakdown' => ['answer_clarity' => 18]],
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'draft',
        'body' => '<p>AEO body.</p>',
        'source' => 'pl',
    ]);

    $revision = ContentRevision::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => '<p>AEO body.</p>',
        'is_active' => true,
    ]);

    $content->update([
        'current_version_id' => $version->id,
        'current_revision_id' => $revision->id,
    ]);

    StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'What is AEO?',
        'answer' => 'AEO is the practice of structuring pages for direct AI answers.',
        'entities' => ['PublishLayer', 'ChatGPT'],
        'order' => 0,
    ]);

    $created = app(ApiKeyService::class)->create(
        workspace: $workspace,
        name: 'Answers API key',
        scopes: [ApiScopes::CONTENT_READ],
        contentDestinationId: null,
    );

    return [
        $site,
        $content->fresh(['answerBlocks']),
        [
            'Authorization' => 'Bearer ' . $plainSiteToken,
            'X-PublishLayer-Site' => 'answers-api.example.com',
        ],
        [
            'Authorization' => 'Bearer ' . $created['plain_text_key'],
        ],
    ];
}
