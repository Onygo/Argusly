<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\ContentSeries;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Api\ApiScopes;
use App\Services\Integrations\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeArticleApiContext(?array $scopes = null): array
{
    $organization = Organization::query()->create([
        'name' => 'Article API Org',
        'slug' => 'article-api-org-'.Str::random(5),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Article API Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Article API Site',
        'site_url' => 'https://article-api.example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $destination = ContentDestination::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Article API Destination',
        'type' => 'api',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [
            'billing_client_site_id' => (string) $site->id,
        ],
    ]);

    $scopes ??= [ApiScopes::CONTENT_READ, ApiScopes::DRAFTS_READ, ApiScopes::BRIEFS_READ, ApiScopes::WEBHOOKS_READ];

    $created = app(ApiKeyService::class)->create(
        workspace: $workspace,
        name: 'Article API key',
        scopes: $scopes,
        contentDestinationId: (string) $destination->id,
    );

    return compact('workspace', 'site', 'destination') + [
        'plain_key' => $created['plain_text_key'],
    ];
}

function articleApiHeaders(string $plainKey): array
{
    return ['Authorization' => 'Bearer '.$plainKey];
}

function createArticleGraph(array $ctx, string $title = 'Canonical Article'): array
{
    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $ctx['workspace']->id,
        'client_site_id' => $ctx['site']->id,
        'content_destination_id' => $ctx['destination']->id,
        'title' => $title,
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'api',
        'language' => 'en',
        'primary_keyword' => 'canonical article keyword',
        'seo_title' => 'Canonical SEO title',
        'seo_meta_description' => 'Canonical SEO description',
        'wp_post_id' => 'legacy-wp-id',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $ctx['site']->id,
        'content_destination_id' => $ctx['destination']->id,
        'content_id' => $content->id,
        'status' => 'done',
        'source' => 'api',
        'progress' => 1,
        'title' => $title.' Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $ctx['site']->id,
        'content_destination_id' => $ctx['destination']->id,
        'status' => 'generated',
        'title' => $title.' Draft',
        'language' => 'en',
        'output_type' => 'kb_article',
        'content_html' => '<p>Body</p>',
    ]);

    $publication = ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'destination_id' => $ctx['destination']->id,
        'client_site_id' => $ctx['site']->id,
        'provider' => ContentPublication::PROVIDER_WORDPRESS,
        'remote_id' => 'canonical-remote-id',
        'remote_url' => 'https://remote.example.com/canonical-article',
        'remote_type' => 'post',
        'remote_status' => 'published',
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'last_delivered_at' => now(),
    ]);

    return compact('content', 'brief', 'draft', 'publication');
}

it('returns article-shaped results from the canonical articles index', function () {
    $ctx = makeArticleApiContext();
    $graph = createArticleGraph($ctx);

    $response = $this->withHeaders(articleApiHeaders($ctx['plain_key']))
        ->getJson('/api/v1/articles?per_page=10&status=published');

    $response->assertOk()
        ->assertJsonPath('data.0.id', (string) $graph['content']->id)
        ->assertJsonPath('data.0.title', 'Canonical Article')
        ->assertJsonPath('data.0.publication.remote.id', 'canonical-remote-id')
        ->assertJsonMissingPath('data.0.wp_post_id')
        ->assertJsonPath('meta.per_page', 10);
});

it('returns a single canonical article and prefers ContentPublication over legacy wp fields', function () {
    $ctx = makeArticleApiContext();
    $graph = createArticleGraph($ctx);

    $response = $this->withHeaders(articleApiHeaders($ctx['plain_key']))
        ->getJson('/api/v1/articles/'.$graph['content']->id);

    $response->assertOk()
        ->assertJsonPath('data.id', (string) $graph['content']->id)
        ->assertJsonPath('data.publication.remote.id', 'canonical-remote-id')
        ->assertJsonPath('data.publication.remote.url', 'https://remote.example.com/canonical-article')
        ->assertJsonMissingPath('data.wp_post_id');
});

it('exposes chain role metadata for chained articles', function () {
    $ctx = makeArticleApiContext();

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $ctx['workspace']->organization_id,
        'site_id' => $ctx['site']->id,
        'name' => 'Canonical Chain',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance',
        'supporting_keywords' => ['workflow checklist'],
        'articles_count' => 1,
        'status' => 'ready',
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $ctx['workspace']->id,
        'client_site_id' => $ctx['site']->id,
        'content_destination_id' => $ctx['destination']->id,
        'series_id' => $series->id,
        'external_key' => 'series-' . $series->id . '-article-1',
        'title' => 'Canonical Chain Article',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'api',
        'language' => 'en',
        'primary_keyword' => 'canonical chain keyword',
    ]);

    app(\App\Services\Content\ContentSeriesArticleSyncService::class)->setPillar($series, 1);

    $response = $this->withHeaders(articleApiHeaders($ctx['plain_key']))
        ->getJson('/api/v1/articles/' . $content->id);

    $response->assertOk()
        ->assertJsonPath('data.chain.series_id', (string) $series->id)
        ->assertJsonPath('data.chain.article_number', 1)
        ->assertJsonPath('data.chain.is_pillar', true)
        ->assertJsonPath('data.chain.role', 'pillar');
});

it('returns article-scoped drafts', function () {
    $ctx = makeArticleApiContext();
    $graph = createArticleGraph($ctx);
    $other = createArticleGraph($ctx, 'Other Article');

    $response = $this->withHeaders(articleApiHeaders($ctx['plain_key']))
        ->getJson('/api/v1/articles/'.$graph['content']->id.'/drafts');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $graph['draft']->id)
        ->assertJsonMissing(['id' => (string) $other['draft']->id]);
});

it('returns canonical publication records for an article', function () {
    $ctx = makeArticleApiContext();
    $graph = createArticleGraph($ctx);

    $response = $this->withHeaders(articleApiHeaders($ctx['plain_key']))
        ->getJson('/api/v1/articles/'.$graph['content']->id.'/publications');

    $response->assertOk()
        ->assertJsonPath('data.0.id', (string) $graph['publication']->id)
        ->assertJsonPath('data.0.remote.id', 'canonical-remote-id')
        ->assertJsonPath('data.0.delivery.status', ContentPublication::STATUS_DELIVERED);
});

it('scopes individual article publications to the owning workspace', function () {
    $ctx = makeArticleApiContext();
    $graph = createArticleGraph($ctx);
    $otherCtx = makeArticleApiContext([ApiScopes::CONTENT_READ, ApiScopes::WEBHOOKS_READ]);
    $otherGraph = createArticleGraph($otherCtx, 'Foreign Article');

    $ok = $this->withHeaders(articleApiHeaders($ctx['plain_key']))
        ->getJson('/api/v1/articles/'.$graph['content']->id.'/publications/'.$graph['publication']->id);

    $ok->assertOk()
        ->assertJsonPath('data.id', (string) $graph['publication']->id);

    $forbidden = $this->withHeaders(articleApiHeaders($ctx['plain_key']))
        ->getJson('/api/v1/articles/'.$otherGraph['content']->id.'/publications/'.$otherGraph['publication']->id);

    $forbidden->assertNotFound();
});

it('exposes real article endpoints in webhook sample links', function () {
    $ctx = makeArticleApiContext([ApiScopes::WEBHOOKS_READ]);

    $response = $this->withHeaders(articleApiHeaders($ctx['plain_key']))
        ->getJson('/api/v1/webhooks/events/article.created');

    $response->assertOk()
        ->assertJsonPath('data.sample_payload.links.article', config('app.url').'/api/v1/articles/art_01HXYZ...');
});

it('keeps legacy brief and draft endpoints working', function () {
    $ctx = makeArticleApiContext();
    $graph = createArticleGraph($ctx);

    $this->withHeaders(articleApiHeaders($ctx['plain_key']))
        ->getJson('/api/v1/briefs/'.$graph['brief']->id)
        ->assertOk()
        ->assertJsonPath('data.id', (string) $graph['brief']->id);

    $this->withHeaders(articleApiHeaders($ctx['plain_key']))
        ->getJson('/api/v1/drafts/'.$graph['draft']->id)
        ->assertOk()
        ->assertJsonPath('data.id', (string) $graph['draft']->id);
});
