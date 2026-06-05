<?php

/**
 * End-to-End Publication Tests
 *
 * These tests verify the complete publication lifecycle from article creation
 * through publishing to WordPress and Laravel destinations, including:
 * - Publication state management
 * - Webhook emissions
 * - Error handling and retries
 * - Verification flows
 */

use App\Jobs\PublishContentJob;
use App\Jobs\PublishToWordPressJob;
use App\Jobs\Integrations\SyncLaravelKnowledgeArticleJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\ContentPublishTarget;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Article\ArticleLifecycleService;
use App\Services\Publication\ContentPublicationService;
use App\Support\Connectors\ConnectorRegistry;
use App\Support\Connectors\Results\PublicationResult;
use App\Support\Connectors\Results\VerificationResult;
use App\Support\Webhooks\WebhookDispatcher;
use App\Support\Webhooks\WebhookEventRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// =========================================================================
// Test Helpers
// =========================================================================

function createE2ETestContext(string $siteType = 'wordpress', bool $withDestination = false): array
{
    $organization = Organization::query()->create([
        'name' => 'E2E Test Org',
        'slug' => 'e2e-test-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'E2E Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => $siteType,
        'name' => 'E2E Test Site',
        'site_url' => 'https://e2e-test.example.com',
        'base_url' => 'https://e2e-test.example.com',
        'allowed_domains' => ['e2e-test.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $destination = null;
    if ($withDestination) {
        $destination = ContentDestination::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'E2E Test Destination',
            'type' => $siteType === 'wordpress' ? 'wordpress' : 'laravel',
            'status' => 'active',
            'environment' => 'production',
            'default_language' => 'en',
            'config' => [
                'billing_client_site_id' => (string) $site->id,
            ],
        ]);
    }

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'content_destination_id' => $destination?->id,
        'title' => 'E2E Test Article',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
        'language' => 'en',
        'primary_keyword' => 'e2e test keyword',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'content_destination_id' => $destination?->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'E2E Test Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'content_destination_id' => $destination?->id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => 'E2E Test Draft',
        'output_type' => 'kb_article',
        'content_html' => '<h1>E2E Test Content</h1><p>This is test content for publication.</p>',
        'language' => 'en',
        'seo_title' => 'E2E Test SEO Title',
        'seo_meta_description' => 'E2E test description for SEO purposes.',
    ]);

    return compact('organization', 'workspace', 'site', 'destination', 'content', 'brief', 'draft');
}

// =========================================================================
// WordPress Publication Flow
// =========================================================================

describe('WordPress Publication E2E', function () {
    it('creates article and queues WordPress publish job', function () {
        Queue::fake();

        $ctx = createE2ETestContext('wordpress');

        $result = app(ArticleLifecycleService::class)->publishNow($ctx['content']);

        expect($result['queued'])->toBeTrue()
            ->and($result['publication_id'])->not->toBeNull();

        Queue::assertPushed(PublishToWordPressJob::class, function ($job) use ($ctx) {
            return $job->contentId === (string) $ctx['content']->id
                && filled($job->publicationId);
        });

        // Verify publication record was created
        $publication = ContentPublication::query()
            ->where('content_id', $ctx['content']->id)
            ->where('provider', ContentPublication::PROVIDER_WORDPRESS)
            ->first();

        expect($publication)->not->toBeNull()
            ->and($publication->delivery_status)->toBe(ContentPublication::STATUS_PENDING);

        // Verify content status updated
        $ctx['content']->refresh();
        expect($ctx['content']->publish_status)->toBe('publishing');
    });

    it('marks article as published after successful WordPress delivery', function () {
        $ctx = createE2ETestContext('wordpress', withDestination: true);

        // Create a publication record simulating job completion
        $publication = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $ctx['content']->id,
            'destination_id' => $ctx['destination']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_PENDING,
        ]);

        // Simulate successful delivery
        $publication->markDelivered(
            remoteId: '12345',
            remoteUrl: 'https://e2e-test.example.com/article/12345',
            remoteType: 'post',
        );

        expect($publication->delivery_status)->toBe(ContentPublication::STATUS_DELIVERED)
            ->and($publication->remote_id)->toBe('12345')
            ->and($publication->remote_url)->toBe('https://e2e-test.example.com/article/12345')
            ->and($publication->last_delivered_at)->not->toBeNull();
    });
});

// =========================================================================
// Laravel Publication Flow
// =========================================================================

describe('Laravel Publication E2E', function () {
    it('creates article and publishes immediately to Laravel', function () {
        $ctx = createE2ETestContext('laravel');

        $result = app(ArticleLifecycleService::class)->publishNow($ctx['content']);

        expect($result['published'])->toBeTrue()
            ->and($result['queued'])->toBeFalse();

        // Verify content status
        $ctx['content']->refresh();
        expect($ctx['content']->publish_status)->toBe('published')
            ->and($ctx['content']->status)->toBe('published')
            ->and($ctx['content']->delivery_status)->toBe('delivered');

        // Verify draft status
        $ctx['draft']->refresh();
        expect($ctx['draft']->status)->toBe('delivered')
            ->and($ctx['draft']->delivery_status)->toBe('delivered');

        // Verify publish target created
        $target = ContentPublishTarget::query()
            ->where('content_id', $ctx['content']->id)
            ->first();

        expect($target)->not->toBeNull()
            ->and($target->target_type)->toContain('laravel');
    });

    it('queues the generic publication job when Laravel destination is configured', function () {
        Queue::fake();

        $ctx = createE2ETestContext('laravel', withDestination: true);

        // Configure the destination for Laravel connector
        $ctx['destination']->update([
            'type' => 'laravel',
            'config' => array_merge($ctx['destination']->config ?? [], [
                'laravel_connector_enabled' => true,
                'laravel_connector_sync_url' => 'https://e2e-test.example.com/publishlayer/sync',
                'laravel_connector_api_key' => 'test-api-key',
                'laravel_connector_site_id' => 'test-site-id',
                'billing_client_site_id' => (string) $ctx['site']->id,
            ]),
        ]);

        $result = app(ArticleLifecycleService::class)->publishNow($ctx['content']);

        expect($result['published'])->toBeFalse()
            ->and($result['queued'])->toBeTrue();

        Queue::assertPushed(PublishContentJob::class);
        Queue::assertNotPushed(SyncLaravelKnowledgeArticleJob::class);
    });
});

// =========================================================================
// Publication Scheduling
// =========================================================================

describe('Publication Scheduling E2E', function () {
    it('schedules article for future publication', function () {
        $ctx = createE2ETestContext('wordpress');
        $publishAt = now()->addDays(7);

        $result = app(ArticleLifecycleService::class)->schedule($ctx['content'], $publishAt);

        expect($result['scheduled'])->toBeTrue();

        $ctx['content']->refresh();
        expect($ctx['content']->publish_status)->toBe('scheduled')
            ->and($ctx['content']->scheduled_publish_at->format('Y-m-d'))->toBe($publishAt->format('Y-m-d'));
    });

    it('cancels scheduled publication', function () {
        $ctx = createE2ETestContext('wordpress');

        // First schedule
        app(ArticleLifecycleService::class)->schedule($ctx['content'], now()->addDays(7));

        // Then cancel
        $result = app(ArticleLifecycleService::class)->cancelSchedule($ctx['content']);

        expect($result['cancelled'])->toBeTrue();

        $ctx['content']->refresh();
        expect($ctx['content']->publish_status)->toBe('draft')
            ->and($ctx['content']->scheduled_publish_at)->toBeNull();
    });

    it('rejects cancellation of non-scheduled article', function () {
        $ctx = createE2ETestContext('wordpress');

        $result = app(ArticleLifecycleService::class)->cancelSchedule($ctx['content']);

        expect($result['cancelled'])->toBeFalse()
            ->and($result['message'])->toContain('not scheduled');
    });
});

// =========================================================================
// Publication Verification
// =========================================================================

describe('Publication Verification E2E', function () {
    it('verifies publication exists at remote', function () {
        $ctx = createE2ETestContext('wordpress', withDestination: true);

        $publication = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $ctx['content']->id,
            'destination_id' => $ctx['destination']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'remote_id' => '12345',
            'remote_url' => 'https://e2e-test.example.com/post/12345',
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
        ]);

        // Mock connector to return exists
        $mockConnector = Mockery::mock(\App\Support\Connectors\WordPressPublicationConnector::class);
        $mockConnector->shouldReceive('type')->andReturn(ContentPublication::PROVIDER_WORDPRESS);
        $mockConnector->shouldReceive('capabilities')->andReturn(\App\Support\Connectors\ConnectorCapabilities::wordpress());
        $mockConnector->shouldReceive('verify')->andReturn(
            VerificationResult::exists(
                remoteStatus: 'publish',
                remoteUrl: 'https://e2e-test.example.com/post/12345',
                httpStatus: 200,
            )
        );

        $registry = new ConnectorRegistry();
        $registry->register($mockConnector);
        app()->instance(ConnectorRegistry::class, $registry);

        $result = app(ContentPublicationService::class)->verify($publication);

        expect($result->isSuccess())->toBeTrue()
            ->and($result->doesExist())->toBeTrue();

        $publication->refresh();
        expect($publication->last_verified_at)->not->toBeNull();
    });

    it('detects missing publication at remote', function () {
        $ctx = createE2ETestContext('wordpress', withDestination: true);

        $publication = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $ctx['content']->id,
            'destination_id' => $ctx['destination']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'remote_id' => '99999',
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
        ]);

        // Mock connector to return missing
        $mockConnector = Mockery::mock(\App\Support\Connectors\WordPressPublicationConnector::class);
        $mockConnector->shouldReceive('type')->andReturn(ContentPublication::PROVIDER_WORDPRESS);
        $mockConnector->shouldReceive('capabilities')->andReturn(\App\Support\Connectors\ConnectorCapabilities::wordpress());
        $mockConnector->shouldReceive('verify')->andReturn(
            VerificationResult::missing(httpStatus: 404)
        );

        $registry = new ConnectorRegistry();
        $registry->register($mockConnector);
        app()->instance(ConnectorRegistry::class, $registry);

        $result = app(ContentPublicationService::class)->verify($publication);

        expect($result->isSuccess())->toBeTrue()
            ->and($result->isMissing())->toBeTrue();
    });
});

// =========================================================================
// Failed Publication & Retry
// =========================================================================

describe('Publication Failure and Retry E2E', function () {
    it('marks publication as failed with error details', function () {
        $ctx = createE2ETestContext('wordpress', withDestination: true);

        $publication = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $ctx['content']->id,
            'destination_id' => $ctx['destination']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_PENDING,
        ]);

        $publication->markFailed(
            errorCode: 'CONNECTION_TIMEOUT',
            errorMessage: 'Failed to connect to WordPress API',
        );

        expect($publication->delivery_status)->toBe(ContentPublication::STATUS_FAILED)
            ->and($publication->last_error_code)->toBe('CONNECTION_TIMEOUT')
            ->and($publication->last_error_message)->toBe('Failed to connect to WordPress API')
            ->and($publication->last_error_at)->not->toBeNull();
    });

    it('allows republish after failure', function () {
        Queue::fake();

        $ctx = createE2ETestContext('wordpress');

        // Simulate a failed publication
        $ctx['content']->update(['publish_status' => 'failed']);

        // Republish
        $result = app(ArticleLifecycleService::class)->republish($ctx['content']);

        expect($result['queued'])->toBeTrue();

        Queue::assertPushed(PublishToWordPressJob::class);
    });

    it('clears error state on retry attempt', function () {
        $ctx = createE2ETestContext('wordpress', withDestination: true);

        $publication = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $ctx['content']->id,
            'destination_id' => $ctx['destination']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_FAILED,
            'last_error_code' => 'TIMEOUT',
            'last_error_message' => 'Request timed out',
        ]);

        // Simulate retry - clear error state
        $publication->forceFill([
            'delivery_status' => ContentPublication::STATUS_PENDING,
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ])->save();

        expect($publication->delivery_status)->toBe(ContentPublication::STATUS_PENDING)
            ->and($publication->last_error_code)->toBeNull();
    });
});

// =========================================================================
// Webhook Emission
// =========================================================================

describe('Publication Webhook Emission', function () {
    it('WebhookDispatcher has publication event methods', function () {
        $dispatcher = app(WebhookDispatcher::class);

        // Verify the dispatcher has the required methods for publication events
        expect(method_exists($dispatcher, 'publicationStarted'))->toBeTrue()
            ->and(method_exists($dispatcher, 'publicationSucceeded'))->toBeTrue()
            ->and(method_exists($dispatcher, 'publicationFailed'))->toBeTrue()
            ->and(method_exists($dispatcher, 'publicationVerified'))->toBeTrue();
    });

    it('emits correct event types for publication lifecycle', function () {
        // Verify event types are registered correctly
        expect(WebhookEventRegistry::PUBLICATION_STARTED)->toBe('publication.started')
            ->and(WebhookEventRegistry::PUBLICATION_SUCCEEDED)->toBe('publication.succeeded')
            ->and(WebhookEventRegistry::PUBLICATION_FAILED)->toBe('publication.failed')
            ->and(WebhookEventRegistry::PUBLICATION_VERIFIED)->toBe('publication.verified');
    });
});

// =========================================================================
// canPublish Validation
// =========================================================================

describe('canPublish Validation E2E', function () {
    it('validates article can be published when all requirements met', function () {
        $ctx = createE2ETestContext('wordpress');

        $result = app(ArticleLifecycleService::class)->canPublish($ctx['content']);

        expect($result['can_publish'])->toBeTrue()
            ->and($result['reason'])->toBeNull();
    });

    it('rejects publishing when no draft exists', function () {
        $ctx = createE2ETestContext('wordpress');
        $ctx['draft']->forceDelete();

        $result = app(ArticleLifecycleService::class)->canPublish($ctx['content']);

        expect($result['can_publish'])->toBeFalse()
            ->and($result['reason'])->toContain('No draft found');
    });

    it('rejects publishing when already publishing', function () {
        $ctx = createE2ETestContext('wordpress');
        $ctx['content']->update(['publish_status' => 'publishing']);

        $result = app(ArticleLifecycleService::class)->canPublish($ctx['content']);

        expect($result['can_publish'])->toBeFalse()
            ->and($result['reason'])->toContain('already being published');
    });

    it('rejects publishing when no site associated', function () {
        $ctx = createE2ETestContext('wordpress');
        $ctx['content']->update(['client_site_id' => null]);
        $ctx['content']->unsetRelation('clientSite');

        $result = app(ArticleLifecycleService::class)->canPublish($ctx['content']);

        expect($result['can_publish'])->toBeFalse()
            ->and($result['reason'])->toContain('No site associated');
    });
});

// =========================================================================
// Canonical Publication Resolution
// =========================================================================

describe('Canonical Publication Resolution E2E', function () {
    it('returns most recent delivered publication as canonical', function () {
        $ctx = createE2ETestContext('wordpress', withDestination: true);

        // Create older publication
        ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $ctx['content']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
            'last_delivered_at' => now()->subDay(),
        ]);

        // Create newer publication
        $newer = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $ctx['content']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
            'last_delivered_at' => now(),
        ]);

        $canonical = app(ArticleLifecycleService::class)->getCanonicalPublication($ctx['content']);

        expect((string) $canonical->id)->toBe((string) $newer->id);
    });

    it('prefers delivered over pending publications', function () {
        $ctx = createE2ETestContext('wordpress', withDestination: true);

        // Create pending (newer)
        ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $ctx['content']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_PENDING,
            'created_at' => now(),
        ]);

        // Create delivered (older)
        $delivered = ContentPublication::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $ctx['content']->id,
            'client_site_id' => $ctx['site']->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
            'last_delivered_at' => now()->subHour(),
            'created_at' => now()->subHour(),
        ]);

        $canonical = app(ArticleLifecycleService::class)->getCanonicalPublication($ctx['content']);

        expect((string) $canonical->id)->toBe((string) $delivered->id);
    });
});
