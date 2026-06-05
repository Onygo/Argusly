<?php

use App\Jobs\PublishToWordPressJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Api\ApiScopes;
use App\Services\Integrations\ApiKeyService;
use App\Support\Connectors\ConnectorRegistry;
use App\Support\Connectors\Results\VerificationResult;
use App\Support\Connectors\WordPressPublicationConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makePublishApiContext(?array $scopes = null): array
{
    $organization = Organization::query()->create([
        'name' => 'Publish API Org',
        'slug' => 'publish-api-org-'.Str::random(5),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Publish API Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Publish API Site',
        'site_url' => 'https://publish-api.example.com',
        'base_url' => 'https://publish-api.example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $destination = ContentDestination::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Publish API Destination',
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

    $scopes ??= [
        ApiScopes::CONTENT_READ,
        ApiScopes::CONTENT_PUBLISH,
        ApiScopes::DRAFTS_READ,
    ];

    $created = app(ApiKeyService::class)->create(
        workspace: $workspace,
        name: 'Publish API key',
        scopes: $scopes,
        contentDestinationId: (string) $destination->id,
    );

    return compact('workspace', 'site', 'destination') + [
        'plain_key' => $created['plain_text_key'],
    ];
}

function publishApiHeaders(string $plainKey): array
{
    return ['Authorization' => 'Bearer '.$plainKey];
}

function createPublishableArticle(array $ctx, string $title = 'Publishable Article'): array
{
    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $ctx['workspace']->id,
        'client_site_id' => $ctx['site']->id,
        'content_destination_id' => $ctx['destination']->id,
        'title' => $title,
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'api',
        'language' => 'en',
        'primary_keyword' => 'publish test keyword',
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
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => $title.' Draft',
        'language' => 'en',
        'output_type' => 'kb_article',
        'content_html' => '<p>Body content</p>',
    ]);

    return compact('content', 'brief', 'draft');
}

describe('Article Publish Action', function () {
    it('queues a publish job when posting to the publish endpoint', function () {
        Queue::fake();

        $ctx = makePublishApiContext();
        $graph = createPublishableArticle($ctx);

        $response = $this->withHeaders(publishApiHeaders($ctx['plain_key']))
            ->postJson('/api/v1/articles/'.$graph['content']->id.'/publish');

        $response->assertStatus(202)
            ->assertJsonStructure([
                'data' => ['publication_id', 'message'],
            ])
            ->assertJsonPath('data.message', 'Publish job has been queued.');

        Queue::assertPushed(PublishToWordPressJob::class, function ($job) use ($graph) {
            return $job->contentId === (string) $graph['content']->id
                && filled($job->publicationId);
        });
    });

    it('creates a publication record when queuing a publish', function () {
        Queue::fake();

        $ctx = makePublishApiContext();
        $graph = createPublishableArticle($ctx);

        $response = $this->withHeaders(publishApiHeaders($ctx['plain_key']))
            ->postJson('/api/v1/articles/'.$graph['content']->id.'/publish');

        $response->assertStatus(202);

        $publication = ContentPublication::query()
            ->where('content_id', $graph['content']->id)
            ->first();

        expect($publication)->not->toBeNull()
            ->and($publication->provider)->toBe(ContentPublication::PROVIDER_WORDPRESS);
    });

    it('returns 403 when missing content:publish scope', function () {
        Queue::fake();

        $ctx = makePublishApiContext([ApiScopes::CONTENT_READ]); // No publish scope
        $graph = createPublishableArticle($ctx);

        $response = $this->withHeaders(publishApiHeaders($ctx['plain_key']))
            ->postJson('/api/v1/articles/'.$graph['content']->id.'/publish');

        $response->assertStatus(403)
            ->assertJsonPath('code', 'AUTH_FORBIDDEN');

        Queue::assertNotPushed(PublishToWordPressJob::class);
    });

    it('returns 404 when trying to publish article from another workspace', function () {
        Queue::fake();

        $ctx1 = makePublishApiContext();
        $ctx2 = makePublishApiContext();
        $graph = createPublishableArticle($ctx1);

        $response = $this->withHeaders(publishApiHeaders($ctx2['plain_key']))
            ->postJson('/api/v1/articles/'.$graph['content']->id.'/publish');

        $response->assertNotFound();
        Queue::assertNotPushed(PublishToWordPressJob::class);
    });

    it('updates content publish_status when queuing a publish', function () {
        Queue::fake();

        $ctx = makePublishApiContext();
        $graph = createPublishableArticle($ctx);

        $this->withHeaders(publishApiHeaders($ctx['plain_key']))
            ->postJson('/api/v1/articles/'.$graph['content']->id.'/publish')
            ->assertStatus(202);

        $graph['content']->refresh();
        expect($graph['content']->publish_status)->toBe('publishing');
    });
});

describe('Article Publish Status', function () {
    it('returns publish status with publication details', function () {
        $ctx = makePublishApiContext();
        $graph = createPublishableArticle($ctx);

        $publication = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $graph['content']->id,
            'destination_id' => $ctx['destination']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'remote_id' => '12345',
            'remote_url' => 'https://publish-api.example.com/post/12345',
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
            'last_delivered_at' => now(),
        ]);

        $graph['content']->update(['publish_status' => 'published']);

        $response = $this->withHeaders(publishApiHeaders($ctx['plain_key']))
            ->getJson('/api/v1/articles/'.$graph['content']->id.'/publish-status');

        $response->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.publications.0.id', (string) $publication->id)
            ->assertJsonPath('data.publications.0.remote_id', '12345')
            ->assertJsonPath('data.publications.0.delivery_status', ContentPublication::STATUS_DELIVERED);
    });

    it('returns draft status when article has no publications', function () {
        $ctx = makePublishApiContext();
        $graph = createPublishableArticle($ctx);

        $response = $this->withHeaders(publishApiHeaders($ctx['plain_key']))
            ->getJson('/api/v1/articles/'.$graph['content']->id.'/publish-status');

        $response->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.publications', []);
    });
});

describe('Publication Verification', function () {
    it('verifies a publication exists at the remote', function () {
        $ctx = makePublishApiContext();
        $graph = createPublishableArticle($ctx);

        $publication = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $graph['content']->id,
            'destination_id' => $ctx['destination']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'remote_id' => '12345',
            'remote_url' => 'https://publish-api.example.com/post/12345',
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
        ]);

        // Mock the connector to return exists result
        $mockConnector = \Mockery::mock(WordPressPublicationConnector::class);
        $mockConnector->shouldReceive('type')->andReturn(ContentPublication::PROVIDER_WORDPRESS);
        $mockConnector->shouldReceive('verify')->once()->andReturn(
            VerificationResult::exists(
                remoteStatus: 'publish',
                remoteUrl: 'https://publish-api.example.com/post/12345',
                httpStatus: 200,
            )
        );

        // Replace the connector in the registry
        $registry = new ConnectorRegistry();
        $registry->register($mockConnector);
        $this->app->instance(ConnectorRegistry::class, $registry);

        $response = $this->withHeaders(publishApiHeaders($ctx['plain_key']))
            ->postJson('/api/v1/articles/'.$graph['content']->id.'/publications/'.$publication->id.'/verify');

        $response->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.status', 'exists')
            ->assertJsonPath('data.remote.id', '12345');

        $publication->refresh();
        expect($publication->last_verified_at)->not->toBeNull();
    });

    it('reports when a publication is missing at the remote', function () {
        $ctx = makePublishApiContext();
        $graph = createPublishableArticle($ctx);

        $publication = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $graph['content']->id,
            'destination_id' => $ctx['destination']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'remote_id' => '99999',
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
        ]);

        // Mock the connector to return missing result
        $mockConnector = \Mockery::mock(WordPressPublicationConnector::class);
        $mockConnector->shouldReceive('type')->andReturn(ContentPublication::PROVIDER_WORDPRESS);
        $mockConnector->shouldReceive('verify')->once()->andReturn(
            VerificationResult::missing(httpStatus: 404)
        );

        // Replace the connector in the registry
        $registry = new ConnectorRegistry();
        $registry->register($mockConnector);
        $this->app->instance(ConnectorRegistry::class, $registry);

        $response = $this->withHeaders(publishApiHeaders($ctx['plain_key']))
            ->postJson('/api/v1/articles/'.$graph['content']->id.'/publications/'.$publication->id.'/verify');

        $response->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.status', 'missing')
            ->assertJsonPath('data.message', 'Remote publication was not found.');
    });

    it('returns 403 when missing content:publish scope for verification', function () {
        $ctx = makePublishApiContext([ApiScopes::CONTENT_READ]); // No publish scope
        $graph = createPublishableArticle($ctx);

        $publication = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $graph['content']->id,
            'destination_id' => $ctx['destination']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'remote_id' => '12345',
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
        ]);

        $response = $this->withHeaders(publishApiHeaders($ctx['plain_key']))
            ->postJson('/api/v1/articles/'.$graph['content']->id.'/publications/'.$publication->id.'/verify');

        $response->assertStatus(403);
    });
});
