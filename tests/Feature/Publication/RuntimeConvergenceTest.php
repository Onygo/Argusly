<?php

use App\Jobs\Integrations\DeliverApiWebhookJob;
use App\Jobs\DeliverDraftJob;
use App\Jobs\PublishToWordPressJob;
use App\Models\ApiWebhook;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteToken;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DraftDelivery\DeliverDraftToWordPress;
use App\Services\Publication\ContentPublicationService;
use App\Services\WordPress\Data\WordPressPost;
use App\Services\WordPress\Data\WordPressPostLookupResult;
use App\Services\WordPress\WordPressConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makePublicationRuntimeContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Publication Runtime Org',
        'slug' => 'publication-runtime-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Publication Runtime Org BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Publication Runtime Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Publication Runtime Site',
        'site_url' => 'https://runtime.example.com',
        'base_url' => 'https://runtime.example.com',
        'allowed_domains' => ['runtime.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'publication-runtime-plan'],
        [
            'name' => 'Publication Runtime Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Runtime Publication Article',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'delivery_status' => 'pending',
        'publish_status' => 'scheduled',
        'scheduled_publish_at' => now()->subMinute(),
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Publication Runtime Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => 'Runtime Publication Draft',
        'content_html' => '<h1>Hello</h1>',
    ]);

    return compact('user', 'workspace', 'site', 'content', 'draft');
}

it('writes wordpress publications canonically and emits webhook events from the runtime publication orchestration path', function () {
    $ctx = makePublicationRuntimeContext();

    ApiWebhook::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'name' => 'Publication Runtime Webhook',
        'target_url' => 'https://hooks.example.com/runtime',
        'secret' => 'runtime-secret',
        'events' => ['publication.started', 'publication.succeeded'],
        'is_active' => true,
        'created_by' => $ctx['user']->id,
    ]);

    Queue::fake();

    $delivery = \Mockery::mock(DeliverDraftToWordPress::class);
    $ctx['draft']->update([
        'status' => 'generated',
        'delivery_status' => 'pending',
    ]);
    $delivery->shouldReceive('primeConnectorDraftForDelivery')->once()->andReturn($ctx['draft']->fresh());
    $delivery->shouldNotReceive('markDelivering');
    $delivery->shouldReceive('beginConnectorPublicationSession')->once();
    $delivery->shouldReceive('deliver')->once()->andReturn([
        'ok' => true,
        'status' => 200,
        'wp_post_id' => '401',
        'body' => json_encode(['published_url' => 'https://runtime.example.com/post-401']),
        'error' => null,
    ]);
    $delivery->shouldReceive('markDelivered')->once();
    $delivery->shouldReceive('endConnectorPublicationSession')->once();
    $this->app->instance(DeliverDraftToWordPress::class, $delivery);
    $this->app->forgetInstance(\App\Support\Connectors\ConnectorRegistry::class);
    $this->app->forgetInstance(\App\Services\Publication\ContentPublicationService::class);

    $destination = app(\App\Services\Publication\WordPressPublicationDestinationResolver::class)
        ->resolveForContent($ctx['content'], $ctx['draft']);

    app(\App\Services\Publication\ContentPublicationService::class)
        ->publish($ctx['content'], $destination, $ctx['draft']);

    $publication = ContentPublication::query()
        ->where('content_id', (string) $ctx['content']->id)
        ->where('provider', ContentPublication::PROVIDER_WORDPRESS)
        ->first();

    expect($publication)->not->toBeNull()
        ->and((string) $publication?->remote_id)->toBe('401')
        ->and((string) $publication?->remote_url)->toBe('https://runtime.example.com/post-401')
        ->and((string) $publication?->delivery_status)->toBe(ContentPublication::STATUS_DELIVERED);

    Queue::assertPushed(DeliverApiWebhookJob::class, function (DeliverApiWebhookJob $job): bool {
        return in_array($job->eventType, ['publication.started', 'publication.succeeded'], true);
    });
});

it('routes wordpress draft repushes through the canonical publication runtime path', function () {
    $ctx = makePublicationRuntimeContext();

    Queue::fake();

    // Track that the service methods were called
    $markPublishingCalled = false;
    $publishCalled = false;

    $publicationService = \Mockery::mock(\App\Services\Publication\ContentPublicationService::class);
    $publicationService->shouldReceive('markFailed')->never(); // Should not fail
    $publicationService->shouldReceive('resolveDestinationForContent')
        ->once()
        ->andReturn(app(\App\Services\Publication\WordPressPublicationDestinationResolver::class)->resolveForContent($ctx['content'], $ctx['draft']));
    $publicationService->shouldReceive('markPublishing')
        ->once()
        ->andReturnUsing(function () use (&$markPublishingCalled) {
            $markPublishingCalled = true;
        });
    $publicationService->shouldReceive('publish')
        ->once()
        ->andReturnUsing(function ($content, $destination, $draft, $options) use (&$publishCalled) {
            $publishCalled = true;

            // Create publication record like the real service would
            $publication = ContentPublication::resolveForDelivery(
                contentId: (string) $content->id,
                destinationId: $destination ? (string) $destination->id : null,
                clientSiteId: $content->client_site_id,
                provider: ContentPublication::PROVIDER_WORDPRESS,
            );
            $publication->forceFill([
                'remote_id' => '499',
                'remote_url' => 'https://runtime.example.com/post-499',
                'delivery_status' => ContentPublication::STATUS_DELIVERED,
                'last_delivered_at' => now(),
            ])->save();

            return \App\Support\Connectors\Results\PublicationResult::success(
                remoteId: '499',
                remoteUrl: 'https://runtime.example.com/post-499',
                remoteType: 'post',
                remoteStatus: 'publish',
            );
        });
    $this->app->instance(\App\Services\Publication\ContentPublicationService::class, $publicationService);

    // Verify draft state before dispatch
    $freshDraft = $ctx['draft']->fresh()->load('clientSite', 'content');
    expect($freshDraft->content)->not->toBeNull()
        ->and(strtolower(trim((string) $freshDraft->clientSite?->type)))->toBe('wordpress');

    // Verify the mock is bound correctly
    $boundService = app(\App\Services\Publication\ContentPublicationService::class);
    expect($boundService)->toBe($publicationService);

    // Run the job manually with injected dependencies
    $job = new DeliverDraftJob((string) $ctx['draft']->id, forceDelivery: true);
    $job->handle(
        $publicationService,
        app(\App\Services\Publication\WordPressPublicationDestinationResolver::class),
    );

    // Verify service was invoked correctly
    expect($markPublishingCalled)->toBeTrue('markPublishing was not called')
        ->and($publishCalled)->toBeTrue('publish was not called');

    $publication = ContentPublication::query()
        ->where('content_id', (string) $ctx['content']->id)
        ->where('provider', ContentPublication::PROVIDER_WORDPRESS)
        ->first();

    expect($publication)->not->toBeNull()
        ->and((string) $publication?->remote_id)->toBe('499')
        ->and((string) $publication?->remote_url)->toBe('https://runtime.example.com/post-499')
        ->and((string) $publication?->delivery_status)->toBe(ContentPublication::STATUS_DELIVERED);
});

it('retries failed wordpress publications against the same canonical publication record', function () {
    $ctx = makePublicationRuntimeContext();

    $firstDelivery = \Mockery::mock(DeliverDraftToWordPress::class);
    $firstDelivery->shouldReceive('primeConnectorDraftForDelivery')->once()->andReturn($ctx['draft']);
    $firstDelivery->shouldNotReceive('markDelivering');
    $firstDelivery->shouldReceive('beginConnectorPublicationSession')->once();
    $firstDelivery->shouldReceive('deliver')->once()->andReturn([
        'ok' => false,
        'status' => 500,
        'body' => 'error',
        'error' => 'Initial failure',
    ]);
    $firstDelivery->shouldReceive('markFailed')->once();
    $firstDelivery->shouldReceive('endConnectorPublicationSession')->once();
    $this->app->instance(DeliverDraftToWordPress::class, $firstDelivery);
    $this->app->forgetInstance(\App\Support\Connectors\ConnectorRegistry::class);
    $this->app->forgetInstance(\App\Services\Publication\ContentPublicationService::class);

    try {
        PublishToWordPressJob::dispatchSync((string) $ctx['content']->id);
    } catch (Throwable) {
    }

    $publication = ContentPublication::query()
        ->where('content_id', (string) $ctx['content']->id)
        ->where('provider', ContentPublication::PROVIDER_WORDPRESS)
        ->firstOrFail();

    expect((string) $publication->delivery_status)->toBe(ContentPublication::STATUS_FAILED);

    $ctx['content']->refresh()->update([
        'publish_status' => 'failed',
    ]);
    $ctx['draft']->refresh()->update([
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'delivery_last_error' => null,
    ]);

    $secondDelivery = \Mockery::mock(DeliverDraftToWordPress::class);
    $secondDelivery->shouldReceive('primeConnectorDraftForDelivery')->once()->andReturn($ctx['draft']->fresh());
    $secondDelivery->shouldNotReceive('markDelivering');
    $secondDelivery->shouldReceive('beginConnectorPublicationSession')->once();
    $secondDelivery->shouldReceive('deliver')->once()->andReturn([
        'ok' => true,
        'status' => 200,
        'wp_post_id' => '402',
        'body' => json_encode(['published_url' => 'https://runtime.example.com/post-402']),
        'error' => null,
    ]);
    $secondDelivery->shouldReceive('markDelivered')->once();
    $secondDelivery->shouldReceive('endConnectorPublicationSession')->once();
    $this->app->instance(DeliverDraftToWordPress::class, $secondDelivery);
    $this->app->forgetInstance(\App\Support\Connectors\ConnectorRegistry::class);
    $this->app->forgetInstance(\App\Services\Publication\ContentPublicationService::class);

    PublishToWordPressJob::dispatchSync((string) $ctx['content']->id);

    $publication->refresh();

    expect((string) $publication->remote_id)->toBe('402')
        ->and((string) $publication->delivery_status)->toBe(ContentPublication::STATUS_DELIVERED);
});

it('guards duplicate dispatch for the same wordpress publication and queues only one job', function () {
    Queue::fake();

    $ctx = makePublicationRuntimeContext();
    $service = app(ContentPublicationService::class);

    $first = $service->dispatchWordPressPublication($ctx['content'], $ctx['draft'], [
        'source' => 'test.duplicate_dispatch.first',
    ]);
    $second = $service->dispatchWordPressPublication($ctx['content'], $ctx['draft'], [
        'source' => 'test.duplicate_dispatch.second',
    ]);

    expect((bool) $first['queued'])->toBeTrue()
        ->and((bool) $second['queued'])->toBeFalse()
        ->and((string) ($first['publication']?->id ?? ''))->not->toBe('');

    Queue::assertPushed(PublishToWordPressJob::class, 1);
});

it('resolves legacy queued jobs against the queued draft context and existing wordpress mapping', function () {
    $ctx = makePublicationRuntimeContext();

    $ctx['content']->update([
        'wp_post_id' => '777',
        'published_url' => 'https://runtime.example.com/post-777',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $ctx['draft']->brief_id,
        'content_id' => (string) $ctx['content']->id,
        'client_site_id' => (string) $ctx['site']->id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => 'Later Draft',
        'content_html' => '<h1>Later</h1>',
    ]);

    $delivery = \Mockery::mock(DeliverDraftToWordPress::class);
    $delivery->shouldReceive('primeConnectorDraftForDelivery')->once()->with((string) $ctx['draft']->id)->andReturn($ctx['draft']->fresh());
    $delivery->shouldNotReceive('markDelivering');
    $delivery->shouldReceive('beginConnectorPublicationSession')->once();
    $delivery->shouldReceive('deliver')->once()->withArgs(function (Draft $draft): bool {
        return (string) $draft->title === 'Runtime Publication Draft';
    })->andReturn([
        'ok' => true,
        'status' => 200,
        'wp_post_id' => '777',
        'body' => json_encode(['published_url' => 'https://runtime.example.com/post-777']),
        'error' => null,
    ]);
    $delivery->shouldReceive('markDelivered')->once();
    $delivery->shouldReceive('endConnectorPublicationSession')->once();
    $this->app->instance(DeliverDraftToWordPress::class, $delivery);
    $this->app->forgetInstance(\App\Support\Connectors\ConnectorRegistry::class);
    $this->app->forgetInstance(ContentPublicationService::class);

    $job = new PublishToWordPressJob((string) $ctx['content']->id);
    $job->draftId = (string) $ctx['draft']->id;
    Bus::dispatchSync($job);

    $publication = ContentPublication::query()
        ->where('content_id', (string) $ctx['content']->id)
        ->where('provider', ContentPublication::PROVIDER_WORDPRESS)
        ->firstOrFail();

    expect((string) $publication->remote_id)->toBe('777')
        ->and((string) $publication->remote_url)->toBe('https://runtime.example.com/post-777')
        ->and((string) $publication->delivery_status)->toBe(ContentPublication::STATUS_DELIVERED);
});

it('gracefully no-ops when a second publish job sees an already claimed or published publication', function () {
    $ctx = makePublicationRuntimeContext();
    $service = app(ContentPublicationService::class);
    $publication = $service->prepareWordPressPublication($ctx['content'], $ctx['draft']);

    expect($publication)->not->toBeNull();

    $delivery = \Mockery::mock(DeliverDraftToWordPress::class);
    $delivery->shouldReceive('primeConnectorDraftForDelivery')->once()->andReturn($ctx['draft']);
    $delivery->shouldNotReceive('markDelivering');
    $delivery->shouldReceive('beginConnectorPublicationSession')->once();
    $delivery->shouldReceive('deliver')->once()->andReturn([
        'ok' => true,
        'status' => 200,
        'wp_post_id' => '903',
        'body' => json_encode(['published_url' => 'https://runtime.example.com/post-903']),
        'error' => null,
    ]);
    $delivery->shouldReceive('markDelivered')->once();
    $delivery->shouldReceive('endConnectorPublicationSession')->once();
    $this->app->instance(DeliverDraftToWordPress::class, $delivery);
    $this->app->forgetInstance(\App\Support\Connectors\ConnectorRegistry::class);
    $this->app->forgetInstance(ContentPublicationService::class);

    PublishToWordPressJob::dispatchSync((string) $ctx['content']->id, (string) $publication->id);
    PublishToWordPressJob::dispatchSync((string) $ctx['content']->id, (string) $publication->id);

    $publication->refresh();

    expect((string) $publication->remote_id)->toBe('903')
        ->and((string) $publication->delivery_status)->toBe(ContentPublication::STATUS_DELIVERED);
});

it('gracefully no-ops when a concurrent worker encounters an already processing publication', function () {
    $ctx = makePublicationRuntimeContext();
    $publication = app(ContentPublicationService::class)->prepareWordPressPublication($ctx['content'], $ctx['draft']);

    expect($publication)->not->toBeNull();

    $publication->forceFill([
        'delivery_status' => 'processing',
    ])->save();

    $delivery = \Mockery::mock(DeliverDraftToWordPress::class);
    $delivery->shouldNotReceive('primeConnectorDraftForDelivery');
    $delivery->shouldNotReceive('markDelivering');
    $this->app->instance(DeliverDraftToWordPress::class, $delivery);
    $this->app->forgetInstance(\App\Support\Connectors\ConnectorRegistry::class);
    $this->app->forgetInstance(ContentPublicationService::class);

    PublishToWordPressJob::dispatchSync((string) $ctx['content']->id, (string) $publication->id);

    expect((string) $publication->fresh()->delivery_status)->toBe('processing');
});

it('verifies wordpress publications through the real controller path and emits publication verified webhooks', function () {
    $ctx = makePublicationRuntimeContext();

    $publication = ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $ctx['content']->id,
        'destination_id' => null,
        'client_site_id' => (string) $ctx['site']->id,
        'provider' => ContentPublication::PROVIDER_WORDPRESS,
        'remote_id' => '401',
        'remote_url' => 'https://runtime.example.com/post-401',
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
    ]);

    $destination = app(\App\Services\Publication\WordPressPublicationDestinationResolver::class)
        ->resolveForContent($ctx['content'], $ctx['draft']);

    $publication->forceFill(['destination_id' => $destination?->id])->save();

    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $ctx['site']->id,
        'workspace_id' => (string) $ctx['workspace']->id,
        'name' => 'verify key',
        'token_hash' => hash('sha256', 'wp-secret'),
        'token_encrypted' => Crypt::encryptString('wp-secret'),
        'key_prefix' => substr('wp-secret', 0, 8),
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
    ]);

    ApiWebhook::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'name' => 'Publication Verify Webhook',
        'target_url' => 'https://hooks.example.com/verify',
        'secret' => 'verify-secret',
        'events' => ['publication.verified'],
        'is_active' => true,
        'created_by' => $ctx['user']->id,
    ]);

    Queue::fake();

    $wpConnector = \Mockery::mock(WordPressConnector::class);
    $wpConnector->shouldReceive('forSite')->once()->andReturnSelf();
    $wpConnector->shouldReceive('postExists')->once()->andReturn(
        new WordPressPostLookupResult(
            exists: true,
            post: new WordPressPost(
                id: '401',
                publishedUrl: 'https://runtime.example.com/post-401',
                status: 'publish',
                modifiedTs: time(),
                httpStatus: 200,
                raw: ['id' => '401', 'link' => 'https://runtime.example.com/post-401', 'status' => 'publish'],
            ),
            httpStatus: 200,
        )
    );
    $this->app->instance(WordPressConnector::class, $wpConnector);
    $this->app->forgetInstance(\App\Support\Connectors\ConnectorRegistry::class);
    $this->app->forgetInstance(\App\Services\Publication\ContentPublicationService::class);

    $this->actingAs($ctx['user'])
        ->post(route('app.content.verify-remote', $ctx['content']))
        ->assertRedirect()
        ->assertSessionHas('status', 'Remote WordPress post verified. The post exists and is accessible.');

    expect($publication->fresh()->last_verified_at)->not->toBeNull();

    Queue::assertPushed(DeliverApiWebhookJob::class, function (DeliverApiWebhookJob $job): bool {
        return $job->eventType === 'publication.verified';
    });
});
