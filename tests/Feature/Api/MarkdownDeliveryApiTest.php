<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentRenderArtifact;
use App\Models\Organization;
use App\Models\SiteToken;
use App\Models\Workspace;
use App\Services\Api\ApiScopes;
use App\Services\Integrations\ApiKeyService;
use App\Services\Markdown\MarkdownArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires connector authentication for markdown delivery', function () {
    [$site, $content] = makeMarkdownDeliveryContext();

    $this->getJson('/api/sites/' . $site->id . '/content/' . $content->id . '/markdown')
        ->assertUnauthorized();
});

it('delivers markdown through a site token within the site scope', function () {
    [$site, $content, $headers] = makeMarkdownDeliveryContext();

    $this->withHeaders($headers)
        ->getJson('/api/sites/' . $site->id . '/content/' . $content->id . '/markdown')
        ->assertOk()
        ->assertJsonPath('content_id', (string) $content->id)
        ->assertJsonPath('locale', 'en')
        ->assertJsonPath('status', 'published')
        ->assertJsonPath('canonical_url', 'https://markdown-api.example.com/blog/argusly-guide')
        ->assertJsonPath('rendered_markdown', "# Argusly guide\n\nEnglish body")
        ->assertJsonPath('rendered_html', '<h1>Argusly guide</h1><p>English body</p>');
});

it('prevents cross site access for site tokens', function () {
    [$siteA, $contentA, $headersA] = makeMarkdownDeliveryContext(host: 'tenant-a.example.com');
    [$siteB] = makeMarkdownDeliveryContext(host: 'tenant-b.example.com');

    $this->withHeaders($headersA)
        ->getJson('/api/sites/' . $siteB->id . '/content/' . $contentA->id . '/markdown')
        ->assertNotFound();
});

it('prevents cross tenant access for workspace api keys', function () {
    [$siteA, $contentA, , $apiHeadersA] = makeMarkdownDeliveryContext(host: 'api-a.example.com');
    [$siteB] = makeMarkdownDeliveryContext(host: 'api-b.example.com');

    $this->withHeaders($apiHeadersA)
        ->getJson('/api/sites/' . $siteB->id . '/content/' . $contentA->id . '/markdown')
        ->assertNotFound();
});

it('returns cache headers and honors etag revalidation', function () {
    [$site, $content, $headers] = makeMarkdownDeliveryContext();

    $response = $this->withHeaders($headers)
        ->getJson('/api/sites/' . $site->id . '/content/' . $content->id . '/markdown');

    $response->assertOk();
    $etag = $response->headers->get('ETag');

    expect($etag)->not->toBeNull()
        ->and($response->headers->get('Last-Modified'))->not->toBeNull();

    $this->withHeaders($headers + ['If-None-Match' => $etag])
        ->get('/api/sites/' . $site->id . '/content/' . $content->id . '/markdown')
        ->assertStatus(304);
});

it('never exposes draft or otherwise ineligible content', function () {
    [$site, $content, $headers] = makeMarkdownDeliveryContext(publishStatus: 'draft', contentStatus: 'draft');

    $this->withHeaders($headers)
        ->getJson('/api/sites/' . $site->id . '/content/' . $content->id . '/markdown')
        ->assertNotFound();
});

it('returns locale specific artifacts when requested', function () {
    [$site, $content, $headers] = makeMarkdownDeliveryContext();

    app(MarkdownArtifactService::class)->storeArtifact($content, [
        'markdown_locale' => 'nl',
        'content_version_id' => $content->current_version_id,
        'rendered_markdown' => "# Argusly gids\n\nNederlandse inhoud",
        'rendered_html' => '<h1>Argusly gids</h1><p>Nederlandse inhoud</p>',
        'markdown_status' => ContentRenderArtifact::STATUS_READY,
        'markdown_source' => ContentRenderArtifact::SOURCE_MANUAL,
        'markdown_generated_at' => now(),
    ]);

    $this->withHeaders($headers)
        ->getJson('/api/sites/' . $site->id . '/content/' . $content->id . '/markdown?locale=nl')
        ->assertOk()
        ->assertJsonPath('locale', 'nl')
        ->assertJsonPath('rendered_markdown', "# Argusly gids\n\nNederlandse inhoud");
});

it('returns a paginated markdown index with fixed visibility rules', function () {
    [$site, $content, $headers] = makeMarkdownDeliveryContext();
    $hiddenContent = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $content->workspace_id,
        'client_site_id' => $site->id,
        'title' => 'Hidden draft content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'api',
        'publish_status' => 'draft',
        'published_url' => 'https://markdown-api.example.com/blog/hidden-draft-content',
        'publish_url_key' => 'hidden-draft-content',
        'canonical_url_key' => 'hidden-draft-content',
        'external_key' => 'hidden-draft-content',
    ]);

    $hiddenVersion = \App\Models\ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $hiddenContent->id,
        'type' => 'draft',
        'body' => '<p>Hidden draft body</p>',
        'source' => 'pl',
    ]);

    $hiddenRevision = \App\Models\ContentRevision::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $hiddenContent->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => '<p>Hidden draft body</p>',
        'is_active' => true,
    ]);

    $hiddenContent->update([
        'current_version_id' => $hiddenVersion->id,
        'current_revision_id' => $hiddenRevision->id,
    ]);

    app(MarkdownArtifactService::class)->storeArtifact($hiddenContent->fresh(['workspace', 'renderArtifacts']), [
        'markdown_locale' => 'en',
        'content_version_id' => $hiddenVersion->id,
        'rendered_markdown' => "# Hidden draft content\n\nHidden draft body",
        'rendered_html' => '<h1>Hidden draft content</h1><p>Hidden draft body</p>',
        'markdown_status' => ContentRenderArtifact::STATUS_READY,
        'markdown_source' => ContentRenderArtifact::SOURCE_MANUAL,
        'markdown_generated_at' => now(),
    ]);

    $this->withHeaders($headers)
        ->getJson('/api/sites/' . $site->id . '/markdown-index?per_page=10')
        ->assertOk()
        ->assertJsonPath('items.0.slug', 'argusly-guide')
        ->assertJsonPath('items.0.locale', 'en')
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonCount(1, 'items');
});

function makeMarkdownDeliveryContext(
    string $host = 'markdown-api.example.com',
    string $publishStatus = 'published',
    string $contentStatus = 'published'
): array {
    Queue::fake();

    $organization = Organization::query()->create([
        'name' => 'Markdown Delivery Org ' . Str::random(4),
        'slug' => 'markdown-delivery-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Markdown Delivery Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en', 'nl'],
    ]);

    $site = ClientSite::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Markdown Delivery Site',
        'site_url' => 'https://' . $host,
        'base_url' => 'https://' . $host,
        'allowed_domains' => [$host],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plainSiteToken = 'arg_site_' . Str::random(48);
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
        'title' => 'Argusly guide',
        'language' => 'en',
        'type' => 'article',
        'status' => $contentStatus,
        'source' => 'api',
        'publish_status' => $publishStatus,
        'published_url' => 'https://' . $host . '/blog/argusly-guide',
        'publish_url_key' => 'argusly-guide',
        'canonical_url_key' => 'argusly-guide',
        'external_key' => 'argusly-guide',
    ]);

    $version = \App\Models\ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'draft',
        'body' => '<p>English body</p>',
        'source' => 'pl',
    ]);

    $revision = \App\Models\ContentRevision::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => '<p>English body</p>',
        'is_active' => true,
    ]);

    $content->update([
        'current_version_id' => $version->id,
        'current_revision_id' => $revision->id,
        'seo_canonical' => 'https://' . $host . '/blog/argusly-guide',
    ]);

    app(MarkdownArtifactService::class)->storeArtifact($content->fresh(['workspace', 'renderArtifacts']), [
        'markdown_locale' => 'en',
        'content_version_id' => $version->id,
        'rendered_markdown' => "# Argusly guide\n\nEnglish body",
        'rendered_html' => '<h1>Argusly guide</h1><p>English body</p>',
        'markdown_status' => ContentRenderArtifact::STATUS_READY,
        'markdown_source' => ContentRenderArtifact::SOURCE_MANUAL,
        'markdown_generated_at' => now(),
    ]);

    $created = app(ApiKeyService::class)->create(
        workspace: $workspace,
        name: 'Markdown API key',
        scopes: [ApiScopes::CONTENT_READ],
        contentDestinationId: null,
    );

    return [
        $site,
        $content->fresh(['workspace', 'seo', 'publications', 'renderArtifacts']),
        [
            'Authorization' => 'Bearer ' . $plainSiteToken,
            'X-Argusly-Site' => $host,
        ],
        [
            'Authorization' => 'Bearer ' . $created['plain_text_key'],
        ],
    ];
}
