<?php

use App\Jobs\Integrations\SyncLaravelKnowledgeArticleJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentDestinationSyncAttempt;
use App\Models\ContentPublication;
use App\Models\ContentPublishTarget;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeLaravelSyncContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Laravel Sync Org',
        'slug' => 'laravel-sync-org-'.Str::random(6),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Laravel Sync Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Laravel Billing Site',
        'site_url' => 'https://client-sync.example.com',
        'base_url' => 'https://client-sync.example.com',
        'allowed_domains' => ['client-sync.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $destination = ContentDestination::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Laravel Connector Destination',
        'type' => 'laravel',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [
            'billing_client_site_id' => (string) $site->id,
            'laravel_connector' => [
                'base_url' => 'https://client-sync.example.com',
                'site_id' => 'sync-site-1',
                'sync_endpoint' => '/argusly/sync',
                'api_key_encrypted' => Crypt::encryptString('sync-secret-123456'),
                'enabled' => true,
                'mode' => 'hosted_views',
            ],
        ],
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'content_destination_id' => $destination->id,
        'title' => 'Sync Job Article',
        'primary_keyword' => 'connector sync',
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'delivery_status' => 'delivered',
        'publish_status' => 'published',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_destination_id' => $destination->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Sync brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'content_destination_id' => $destination->id,
        'status' => 'generated',
        'title' => 'Sync Job Draft',
        'content_html' => '<p>Sync body</p>',
        'meta' => [
            'slug' => 'sync-job-article',
        ],
    ]);

    $target = ContentPublishTarget::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'content_destination_id' => $destination->id,
        'target_type' => 'laravel_connector',
        'target_identifier' => (string) $content->id,
        'sync_status' => 'queued',
        'seo_sync_status' => 'pending',
        'seo_sync_mode' => 'push',
        'meta' => [
            'remote_sync_status' => 'queued',
        ],
    ]);

    return compact('organization', 'workspace', 'site', 'destination', 'content', 'target');
}

it('syncs laravel knowledge articles successfully and stores attempt diagnostics', function () {
    $ctx = makeLaravelSyncContext();

    Http::fake([
        'https://client-sync.example.com/argusly/sync' => Http::response([
            'ok' => true,
            'message' => 'Synced',
            'url' => 'https://client-sync.example.com/knowledge/sync-job-article',
        ], 200),
    ]);

    $job = new SyncLaravelKnowledgeArticleJob((string) $ctx['content']->id, 'tests.success');
    $job->handle(
        app(\App\Services\Integrations\LaravelConnectorDestinationResolver::class),
        app(\App\Services\Integrations\LaravelConnectorPublisher::class),
    );

    Http::assertSent(function ($request) use ($ctx) {
        return $request->url() === 'https://client-sync.example.com/argusly/sync'
            && $request->hasHeader('X-Argusly-API-Key', 'sync-secret-123456')
            && $request->hasHeader('X-Argusly-Site', 'sync-site-1')
            && data_get($request->data(), 'article.id') === (string) $ctx['content']->id;
    });

    $attempt = ContentDestinationSyncAttempt::query()->first();

    expect($attempt)->not->toBeNull()
        ->and((string) $attempt?->status)->toBe('delivered')
        ->and((int) $attempt?->response_status)->toBe(200)
        ->and($attempt?->delivered_at)->not->toBeNull();

    expect((string) $ctx['target']->fresh()->sync_status)->toBe('synced')
        ->and((string) data_get($ctx['target']->fresh()->meta, 'remote_sync_status'))->toBe('synced')
        ->and((bool) data_get($ctx['target']->fresh()->meta, 'published_url_confirmed'))->toBeTrue();

    $publication = ContentPublication::query()
        ->where('content_id', (string) $ctx['content']->id)
        ->where('destination_id', (string) $ctx['destination']->id)
        ->first();

    expect($publication)->not->toBeNull()
        ->and((string) $publication?->provider)->toBe(ContentPublication::PROVIDER_LARAVEL)
        ->and((string) $publication?->remote_id)->toBe((string) $ctx['content']->id)
        ->and((string) $publication?->delivery_status)->toBe(ContentPublication::STATUS_DELIVERED);
});

it('marks retryable laravel sync attempts as failed and keeps retry metadata', function () {
    $ctx = makeLaravelSyncContext();

    Http::fake([
        'https://client-sync.example.com/argusly/sync' => Http::response([
            'ok' => false,
            'message' => 'Connector unavailable',
        ], 500),
    ]);

    $job = new SyncLaravelKnowledgeArticleJob((string) $ctx['content']->id, 'tests.failure');

    expect(fn () => $job->handle(
        app(\App\Services\Integrations\LaravelConnectorDestinationResolver::class),
        app(\App\Services\Integrations\LaravelConnectorPublisher::class),
    ))->toThrow(RuntimeException::class, 'Connector unavailable');

    $attempt = ContentDestinationSyncAttempt::query()->firstOrFail();

    expect((string) $attempt->status)->toBe('failed')
        ->and((int) $attempt->response_status)->toBe(500)
        ->and($attempt->failed_at)->not->toBeNull()
        ->and($attempt->next_retry_at)->not->toBeNull();

    expect((string) $ctx['target']->fresh()->sync_status)->toBe('failed')
        ->and((string) data_get($ctx['target']->fresh()->meta, 'remote_sync_status'))->toBe('failed')
        ->and((string) $ctx['content']->fresh()->publish_error)->toContain('Laravel connector sync failed');

    $publication = ContentPublication::query()
        ->where('content_id', (string) $ctx['content']->id)
        ->where('destination_id', (string) $ctx['destination']->id)
        ->first();

    expect($publication)->not->toBeNull()
        ->and((string) $publication?->delivery_status)->toBe(ContentPublication::STATUS_FAILED);

    expect($job->backoff())->toBe([30, 120, 300, 900]);
});

it('fails permanent laravel sync errors without scheduling another retry', function () {
    $ctx = makeLaravelSyncContext();

    Http::fake([
        'https://client-sync.example.com/argusly/sync' => Http::response([
            'ok' => false,
            'message' => 'The given data was invalid.',
            'errors' => [
                'article.slug' => ['The slug has already been taken.'],
            ],
        ], 422),
    ]);

    $job = new SyncLaravelKnowledgeArticleJob((string) $ctx['content']->id, 'tests.permanent-failure');
    $job->withFakeQueueInteractions();
    $job->handle(
        app(\App\Services\Integrations\LaravelConnectorDestinationResolver::class),
        app(\App\Services\Integrations\LaravelConnectorPublisher::class),
    );

    $attempt = ContentDestinationSyncAttempt::query()->firstOrFail();

    expect((string) $attempt->status)->toBe('failed')
        ->and((int) $attempt->response_status)->toBe(422)
        ->and($attempt->failed_at)->not->toBeNull()
        ->and($attempt->next_retry_at)->toBeNull()
        ->and((string) $ctx['target']->fresh()->sync_status)->toBe('failed')
        ->and((string) data_get($ctx['target']->fresh()->meta, 'last_sync_error'))->toContain('The given data was invalid.');

    expect(ContentPublication::query()
        ->where('content_id', (string) $ctx['content']->id)
        ->where('destination_id', (string) $ctx['destination']->id)
        ->first()?->delivery_status)->toBe(ContentPublication::STATUS_FAILED);
});

it('marks remote delete operations as deleted after successful delivery', function () {
    $ctx = makeLaravelSyncContext();

    Http::fake([
        'https://client-sync.example.com/argusly/sync' => Http::response([
            'ok' => true,
            'message' => 'Deleted',
        ], 200),
    ]);

    $job = new SyncLaravelKnowledgeArticleJob((string) $ctx['content']->id, 'tests.delete', 'deleted');
    $job->handle(
        app(\App\Services\Integrations\LaravelConnectorDestinationResolver::class),
        app(\App\Services\Integrations\LaravelConnectorPublisher::class),
    );

    expect((string) $ctx['target']->fresh()->sync_status)->toBe('deleted')
        ->and((string) data_get($ctx['target']->fresh()->meta, 'remote_sync_status'))->toBe('deleted')
        ->and((string) data_get($ctx['target']->fresh()->meta, 'last_synced_operation'))->toBe('deleted');
});
