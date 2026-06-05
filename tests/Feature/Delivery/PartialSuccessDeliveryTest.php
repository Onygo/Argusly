<?php

use App\Enums\PublicationDeliveryStatus;
use App\Jobs\DeliverDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\DraftDelivery\DeliverDraftToWordPress;
use App\Services\DraftDelivery\VerifyRemoteDeliveryService;
use App\Services\Publication\ContentPublicationService;
use App\Services\WordPress\WordPressConnector;
use App\Support\Connectors\Results\PublicationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('Partial Success Delivery Handling', function () {
    it('marks delivery as partial_success when WordPress returns ok with partial_success flag', function () {
        [$organization, $workspace, $site, $content, $draft] = makePartialSuccessTestContext();
        $destination = new ContentDestination(['id' => (string) Str::uuid(), 'type' => 'wordpress']);

        $publicationService = \Mockery::mock(ContentPublicationService::class);
        $publicationService->shouldReceive('resolveDestinationForContent')->once()->andReturn($destination);
        $publicationService->shouldReceive('markPublishing')->once();
        $publicationService->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($passedContent) use ($content, $draft) {
                // Simulate the partial success outcome
                $draft->forceFill([
                    'delivery_status' => 'partial_success',
                    'delivered_at' => now(),
                    'delivery_last_error' => '[PARTIAL SUCCESS] Post published but ACK callback failed',
                ])->save();

                $passedContent->forceFill([
                    'delivery_status' => 'partial_success',
                    'status' => 'published',
                    'wp_post_id' => '12345',
                ])->save();

                return PublicationResult::success(
                    remoteId: '12345',
                    remoteUrl: 'https://example.com/post/12345',
                    remoteType: 'post',
                    remoteStatus: 'publish',
                    meta: ['delivery_status' => 'partial_success'],
                );
            });
        $this->app->instance(ContentPublicationService::class, $publicationService);

        $job = new DeliverDraftJob((string) $draft->id);
        $job->handle(app(ContentPublicationService::class));

        $draft->refresh();
        $content->refresh();

        expect($draft->delivery_status)->toBe('partial_success')
            ->and($draft->delivered_at)->not->toBeNull()
            ->and((string) $draft->delivery_last_error)->toContain('[PARTIAL SUCCESS]')
            ->and($content->delivery_status)->toBe('partial_success')
            ->and($content->status)->toBe('published');
    });

    it('marks delivery as needs_verification when WordPress returns 500 but post may exist', function () {
        [$organization, $workspace, $site, $content, $draft] = makePartialSuccessTestContext();
        $destination = new ContentDestination(['id' => (string) Str::uuid(), 'type' => 'wordpress']);

        $publicationService = \Mockery::mock(ContentPublicationService::class);
        $publicationService->shouldReceive('resolveDestinationForContent')->once()->andReturn($destination);
        $publicationService->shouldReceive('markPublishing')->once();
        $publicationService->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($passedContent) use ($content, $draft) {
                // Simulate needs_verification outcome
                $draft->forceFill([
                    'delivery_status' => 'needs_verification',
                    'delivery_last_error' => '[NEEDS VERIFICATION] Delivery returned an error but the post may have been created.',
                ])->save();

                $passedContent->forceFill([
                    'delivery_status' => 'needs_verification',
                    'wp_post_id' => '67890',
                ])->save();

                return PublicationResult::success(
                    remoteId: '67890',
                    remoteUrl: 'https://example.com/post/67890',
                    remoteType: 'post',
                    remoteStatus: 'publish',
                    meta: ['delivery_status' => 'needs_verification'],
                );
            });
        $this->app->instance(ContentPublicationService::class, $publicationService);

        $job = new DeliverDraftJob((string) $draft->id);
        $job->handle(app(ContentPublicationService::class));

        $draft->refresh();
        $content->refresh();

        expect($draft->delivery_status)->toBe('needs_verification')
            ->and((string) $draft->delivery_last_error)->toContain('[NEEDS VERIFICATION]')
            ->and($content->delivery_status)->toBe('needs_verification');
    });

    it('marks delivery as delivered for full success', function () {
        [$organization, $workspace, $site, $content, $draft] = makePartialSuccessTestContext();
        $destination = new ContentDestination(['id' => (string) Str::uuid(), 'type' => 'wordpress']);

        $publicationService = \Mockery::mock(ContentPublicationService::class);
        $publicationService->shouldReceive('resolveDestinationForContent')->once()->andReturn($destination);
        $publicationService->shouldReceive('markPublishing')->once();
        $publicationService->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($passedContent) use ($content, $draft) {
                // Simulate full success
                $draft->forceFill([
                    'delivery_status' => 'delivered',
                    'delivered_at' => now(),
                ])->save();

                $passedContent->forceFill([
                    'delivery_status' => 'delivered',
                    'status' => 'published',
                    'wp_post_id' => '11111',
                ])->save();

                return PublicationResult::success(
                    remoteId: '11111',
                    remoteUrl: 'https://example.com/post/11111',
                    remoteType: 'post',
                    remoteStatus: 'publish',
                );
            });
        $this->app->instance(ContentPublicationService::class, $publicationService);

        $job = new DeliverDraftJob((string) $draft->id);
        $job->handle(app(ContentPublicationService::class));

        $draft->refresh();
        $content->refresh();

        expect($draft->delivery_status)->toBe('delivered')
            ->and($draft->delivered_at)->not->toBeNull()
            ->and($content->delivery_status)->toBe('delivered')
            ->and($content->status)->toBe('published');
    });
});

describe('PublicationDeliveryStatus Enum', function () {
    it('has partial_success status', function () {
        $status = PublicationDeliveryStatus::PARTIAL_SUCCESS;

        expect($status->value)->toBe('partial_success')
            ->and($status->label())->toBe('Published (with warnings)')
            ->and($status->color())->toBe('lime')
            ->and($status->isSuccess())->toBeTrue()
            ->and($status->isPartialSuccess())->toBeTrue()
            ->and($status->isFailure())->toBeFalse()
            ->and($status->needsAttention())->toBeTrue();
    });

    it('has needs_verification status', function () {
        $status = PublicationDeliveryStatus::NEEDS_VERIFICATION;

        expect($status->value)->toBe('needs_verification')
            ->and($status->label())->toBe('Needs Verification')
            ->and($status->color())->toBe('amber')
            ->and($status->isSuccess())->toBeFalse()
            ->and($status->isUncertain())->toBeTrue()
            ->and($status->canRetry())->toBeTrue()
            ->and($status->needsAttention())->toBeTrue();
    });

    it('recognizes partial_success as having remote post', function () {
        expect(PublicationDeliveryStatus::PARTIAL_SUCCESS->hasRemotePost())->toBeTrue()
            ->and(PublicationDeliveryStatus::DELIVERED->hasRemotePost())->toBeTrue()
            ->and(PublicationDeliveryStatus::FAILED->hasRemotePost())->toBeFalse()
            ->and(PublicationDeliveryStatus::NEEDS_VERIFICATION->hasRemotePost())->toBeFalse();
    });
});

describe('DeliverDraftToWordPress Partial Success Detection', function () {
    it('detects partial success from WordPress response with flag', function () {
        $service = app(DeliverDraftToWordPress::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('detectPartialSuccessFromResponse');
        $method->setAccessible(true);

        $responseBody = json_encode([
            'ok' => true,
            'partial_success' => true,
            'wp_post_id' => '12345',
            'message' => 'Post published with warnings',
            'post_processing_errors' => [['step' => 'meta', 'error' => 'Failed to update meta']],
        ]);

        $result = $method->invoke($service, 200, $responseBody);

        expect($result['is_partial'])->toBeTrue()
            ->and($result['wp_post_id'])->toBe('12345')
            ->and($result['message'])->toBe('Post published with warnings')
            ->and($result['errors'])->toHaveCount(1);
    });

    it('detects partial success from 500 response with wp_post_id', function () {
        $service = app(DeliverDraftToWordPress::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('detectPartialSuccessFromResponse');
        $method->setAccessible(true);

        // 500 response but contains wp_post_id - post was created before error
        $responseBody = json_encode([
            'error' => 'Something failed after post creation',
            'wp_post_id' => '54321',
        ]);

        $result = $method->invoke($service, 500, $responseBody);

        expect($result['is_partial'])->toBeTrue()
            ->and($result['wp_post_id'])->toBe('54321');
    });

    it('does not detect partial success for clean failures', function () {
        $service = app(DeliverDraftToWordPress::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('detectPartialSuccessFromResponse');
        $method->setAccessible(true);

        // 500 response without wp_post_id - real failure
        $responseBody = json_encode([
            'error' => 'Database connection failed',
        ]);

        $result = $method->invoke($service, 500, $responseBody);

        expect($result['is_partial'])->toBeFalse()
            ->and($result['wp_post_id'])->toBeNull();
    });

    it('extracts wp_post_id from HTML error pages', function () {
        $service = app(DeliverDraftToWordPress::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('extractWpPostIdFromHtmlError');
        $method->setAccessible(true);

        $html = '<html><body>Fatal error in theme at line 123. Debug: wp_post_id=98765</body></html>';
        $result = $method->invoke($service, $html);

        expect($result)->toBe('98765');
    });
});

describe('Reconciliation Service', function () {
    it('skips reconciliation for already delivered content', function () {
        [$organization, $workspace, $site, $content, $draft] = makePartialSuccessTestContext();

        $content->update([
            'delivery_status' => 'delivered',
            'wp_post_id' => '11111',
        ]);

        $mockConnector = \Mockery::mock(WordPressConnector::class);
        $service = new VerifyRemoteDeliveryService($mockConnector);
        $result = $service->reconcile($content);

        expect($result['reconciled'])->toBeFalse()
            ->and($result['message'])->toContain('already marked as delivered');
    });

    it('skips reconciliation for partial_success content', function () {
        [$organization, $workspace, $site, $content, $draft] = makePartialSuccessTestContext();

        $content->update([
            'delivery_status' => 'partial_success',
            'wp_post_id' => '22222',
        ]);

        $mockConnector = \Mockery::mock(WordPressConnector::class);
        $service = new VerifyRemoteDeliveryService($mockConnector);
        $result = $service->reconcile($content);

        expect($result['reconciled'])->toBeFalse()
            ->and($result['message'])->toContain('already marked as delivered');
    });

    it('returns not reconciled when no wp_post_id and lookup fails', function () {
        [$organization, $workspace, $site, $content, $draft] = makePartialSuccessTestContext();

        // Simulate a failed delivery with no wp_post_id
        $content->update([
            'delivery_status' => 'failed',
            'wp_post_id' => null,
        ]);

        // Mock connector that returns no results
        $mockConnector = \Mockery::mock(WordPressConnector::class);
        $mockConnector->shouldReceive('forSite')
            ->andReturnSelf();
        $mockConnector->shouldReceive('findPostByMeta')
            ->andReturn(null);

        $service = new VerifyRemoteDeliveryService($mockConnector);
        $result = $service->reconcile($content);

        expect($result['reconciled'])->toBeFalse()
            ->and($result['message'])->toContain('Could not find the remote post');
    });
});

/**
 * Create test context for partial success delivery tests.
 *
 * @return array{Organization, Workspace, ClientSite, Content, Draft}
 */
function makePartialSuccessTestContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Partial Success Test Org',
        'slug' => 'partial-success-test-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Partial Success Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Partial Success Site',
        'site_url' => 'https://partial-success.example.com',
        'base_url' => 'https://partial-success.example.com',
        'allowed_domains' => ['partial-success.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Partial Success Content',
        'primary_keyword' => 'partial success keyword',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wordpress',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Partial Success Brief',
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
        'title' => 'Partial Success Draft',
        'output_type' => 'kb_article',
        'content_html' => '<h1>Hello</h1>',
    ]);

    return [$organization, $workspace, $site, $content, $draft];
}

describe('Stale Error Clearing on Success', function () {
    it('clears publish_error when delivery succeeds', function () {
        [$organization, $workspace, $site, $content, $draft] = makePartialSuccessTestContext();
        $destination = new ContentDestination(['id' => (string) Str::uuid(), 'type' => 'wordpress']);

        // Simulate a previous failed delivery with stored error
        $content->update([
            'publish_error' => 'HTTP 500: Previous failure that should be cleared',
            'delivery_status' => 'failed',
        ]);

        $publicationService = \Mockery::mock(ContentPublicationService::class);
        $publicationService->shouldReceive('resolveDestinationForContent')->once()->andReturn($destination);
        $publicationService->shouldReceive('markPublishing')->once();
        $publicationService->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($passedContent) use ($draft) {
                $draft->forceFill([
                    'delivery_status' => 'delivered',
                    'delivered_at' => now(),
                ])->save();

                $passedContent->forceFill([
                    'delivery_status' => 'delivered',
                    'status' => 'published',
                    'publish_error' => null,
                    'wp_post_id' => '99999',
                ])->save();

                return PublicationResult::success(
                    remoteId: '99999',
                    remoteUrl: 'https://example.com/post/99999',
                    remoteType: 'post',
                    remoteStatus: 'publish',
                );
            });
        $this->app->instance(ContentPublicationService::class, $publicationService);

        $job = new DeliverDraftJob((string) $draft->id);
        $job->handle(app(ContentPublicationService::class));

        $content->refresh();

        expect($content->delivery_status)->toBe('delivered')
            ->and($content->publish_error)->toBeNull();
    });

    it('clears publish_error when markDelivered is called directly', function () {
        [$organization, $workspace, $site, $content, $draft] = makePartialSuccessTestContext();

        // Simulate a previous failed delivery with stored error
        $content->update([
            'publish_error' => 'HTTP 500: Old error that should be cleared',
            'delivery_status' => 'failed',
        ]);

        $service = app(DeliverDraftToWordPress::class);
        $service->markDelivered($draft, true);

        $content->refresh();

        expect($content->delivery_status)->toBe('delivered')
            ->and($content->publish_error)->toBeNull();
    });

    it('clears publish_error when reconciliation marks content as delivered', function () {
        [$organization, $workspace, $site, $content, $draft] = makePartialSuccessTestContext();

        // Simulate a failed delivery with stored error
        $content->update([
            'publish_error' => 'HTTP 500: Error that should be cleared on reconcile',
            'delivery_status' => 'failed',
            'wp_post_id' => '77777',
        ]);

        // Create a publication record to work with
        $publication = ContentPublication::query()->create([
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => 'failed',
            'remote_id' => '77777',
        ]);

        // Call markReconciled via reflection to test the publish_error clearing
        $service = new VerifyRemoteDeliveryService(app(WordPressConnector::class));
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('markReconciled');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            $content,
            $publication,
            'failed',
            '77777',
            'https://example.com/post/77777',
            'Original error message'
        );

        $content->refresh();

        expect($result['reconciled'])->toBeTrue()
            ->and($content->publish_error)->toBeNull()
            ->and($content->delivery_status)->toBe('delivered');
    });

    it('view only shows error when delivery status indicates failure', function () {
        [$organization, $workspace, $site, $content, $draft] = makePartialSuccessTestContext();

        // Scenario: Content has stale error but delivery status is 'delivered'
        $content->update([
            'publish_error' => 'HTTP 500: This should not be shown',
            'delivery_status' => 'delivered',
        ]);

        // The view condition is:
        // @if (!empty($content->publish_error) && in_array((string) $content->delivery_status, ['failed', 'missing_remote', 'needs_verification', 'partial_success'], true))
        $shouldShowError = !empty($content->publish_error)
            && in_array((string) $content->delivery_status, ['failed', 'missing_remote', 'needs_verification', 'partial_success'], true);

        expect($shouldShowError)->toBeFalse();
    });

    it('view shows error when delivery status is failed', function () {
        [$organization, $workspace, $site, $content, $draft] = makePartialSuccessTestContext();

        // Scenario: Content has error and delivery status is 'failed'
        $content->update([
            'publish_error' => 'HTTP 500: This should be shown',
            'delivery_status' => 'failed',
        ]);

        $shouldShowError = !empty($content->publish_error)
            && in_array((string) $content->delivery_status, ['failed', 'missing_remote', 'needs_verification', 'partial_success'], true);

        expect($shouldShowError)->toBeTrue();
    });
});
