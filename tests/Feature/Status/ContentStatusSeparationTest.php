<?php

use App\Enums\ContentLifecycleStatus;
use App\Enums\PublicationDeliveryStatus;
use App\Enums\RemoteExistenceStatus;
use App\Enums\RemotePublishStatus;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\DraftDelivery\DeliverDraftToWordPress;
use App\Services\Entitlements\WorkspaceEntitlementsService;
use App\View\Presenters\ContentStatusPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ============================================================================
// Enum Tests
// ============================================================================

it('maps legacy content status to lifecycle enum', function () {
    expect(ContentLifecycleStatus::fromLegacyStatus('brief'))->toBe(ContentLifecycleStatus::BRIEF)
        ->and(ContentLifecycleStatus::fromLegacyStatus('draft'))->toBe(ContentLifecycleStatus::DRAFT)
        ->and(ContentLifecycleStatus::fromLegacyStatus('review'))->toBe(ContentLifecycleStatus::REVIEW)
        ->and(ContentLifecycleStatus::fromLegacyStatus('published'))->toBe(ContentLifecycleStatus::PUBLISHED)
        ->and(ContentLifecycleStatus::fromLegacyStatus('archived'))->toBe(ContentLifecycleStatus::ARCHIVED);
});

it('maps legacy delivery status to publication delivery enum', function () {
    expect(PublicationDeliveryStatus::fromLegacyStatus('pending'))->toBe(PublicationDeliveryStatus::PENDING)
        ->and(PublicationDeliveryStatus::fromLegacyStatus('delivered'))->toBe(PublicationDeliveryStatus::DELIVERED)
        ->and(PublicationDeliveryStatus::fromLegacyStatus('failed'))->toBe(PublicationDeliveryStatus::FAILED)
        ->and(PublicationDeliveryStatus::fromLegacyStatus('missing_remote'))->toBe(PublicationDeliveryStatus::MISSING_REMOTE);
});

it('maps HTTP status to remote existence enum', function () {
    expect(RemoteExistenceStatus::fromHttpStatus(200))->toBe(RemoteExistenceStatus::EXISTS)
        ->and(RemoteExistenceStatus::fromHttpStatus(404))->toBe(RemoteExistenceStatus::MISSING)
        ->and(RemoteExistenceStatus::fromHttpStatus(410))->toBe(RemoteExistenceStatus::DELETED)
        ->and(RemoteExistenceStatus::fromHttpStatus(null))->toBe(RemoteExistenceStatus::UNKNOWN);
});

it('maps WordPress status to remote publish enum', function () {
    expect(RemotePublishStatus::fromWordPressStatus('publish'))->toBe(RemotePublishStatus::PUBLISHED)
        ->and(RemotePublishStatus::fromWordPressStatus('draft'))->toBe(RemotePublishStatus::DRAFT)
        ->and(RemotePublishStatus::fromWordPressStatus('future'))->toBe(RemotePublishStatus::SCHEDULED)
        ->and(RemotePublishStatus::fromWordPressStatus('private'))->toBe(RemotePublishStatus::PRIVATE);
});

// ============================================================================
// Presenter Tests
// ============================================================================

it('presenter returns correct lifecycle status from content', function () {
    [$workspace, $site, $content] = makeStatusTestContext();

    $content->update(['status' => 'draft']);
    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->lifecycleStatus())->toBe(ContentLifecycleStatus::DRAFT)
        ->and($presenter->lifecycleLabel())->toBe('Draft')
        ->and($presenter->lifecycleColor())->toBe('amber');
});

it('presenter returns correct delivery status from publication', function () {
    [$workspace, $site, $content] = makeStatusTestContext();

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $publication->markDelivered('12345', 'https://example.com/post/12345');

    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->deliveryStatus())->toBe(PublicationDeliveryStatus::DELIVERED)
        ->and($presenter->deliveryLabel())->toBe('Delivered')
        ->and($presenter->hasDeliveryError())->toBeFalse();
});

it('presenter correctly identifies failed delivery state', function () {
    [$workspace, $site, $content] = makeStatusTestContext();

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $publication->markFailed('500', 'Server error');

    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->deliveryStatus())->toBe(PublicationDeliveryStatus::FAILED)
        ->and($presenter->hasDeliveryError())->toBeTrue()
        ->and($presenter->lastErrorMessage())->toBe('Server error');
});

it('presenter isFullyPublished requires all conditions', function () {
    [$workspace, $site, $content] = makeStatusTestContext();

    // Not yet delivered
    $presenter = ContentStatusPresenter::for($content);
    expect($presenter->isFullyPublished())->toBeFalse();

    // Delivered but content status is still draft
    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $publication->markDelivered('12345', 'https://example.com');

    $content->update(['status' => 'published', 'delivery_status' => 'delivered']);
    $presenter = ContentStatusPresenter::for($content->fresh());
    expect($presenter->isFullyPublished())->toBeTrue();
});

it('presenter identifies partial publication when remote is missing', function () {
    [$workspace, $site, $content] = makeStatusTestContext();

    $content->update(['status' => 'published', 'delivery_status' => 'missing_remote']);

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $publication->markDelivered('12345', 'https://example.com');
    $publication->markMissingRemote('12345');

    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->isPartiallyPublished())->toBeTrue()
        ->and($presenter->needsAttention())->toBeTrue();
});

it('presenter returns secondary badge only when relevant', function () {
    [$workspace, $site, $content] = makeStatusTestContext();

    // No publication - no secondary badge
    $presenter = ContentStatusPresenter::for($content);
    expect($presenter->secondaryBadge())->toBeNull();

    // With failed publication - shows badge
    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $publication->markFailed('500', 'Error');

    $presenter = ContentStatusPresenter::for($content->fresh());
    $badge = $presenter->secondaryBadge();

    expect($badge)->not->toBeNull()
        ->and($badge['label'])->toBe('Failed')
        ->and($badge['color'])->toBe('red');
});

// ============================================================================
// Status Separation Tests
// ============================================================================

it('failed delivery does not corrupt content lifecycle status', function () {
    [$workspace, $site, $content, $draft] = makeStatusTestContextWithDraft();

    // Content starts as 'draft'
    $content->update(['status' => 'draft']);

    $delivery = \Mockery::mock(
        DeliverDraftToWordPress::class,
        [app(WorkspaceEntitlementsService::class)]
    )->makePartial();

    // Mark the delivery as failed
    $delivery->markFailed($draft->fresh(), 'Connection timeout');

    $content->refresh();

    // Content lifecycle status should remain 'draft', NOT changed by failed delivery
    expect($content->status)->toBe('draft')
        ->and($content->delivery_status)->toBe('failed');
});

it('missing remote content is not shown as fully published', function () {
    [$workspace, $site, $content] = makeStatusTestContext();

    // Setup: content was published but remote is now missing
    $content->update([
        'status' => 'published',
        'delivery_status' => 'missing_remote',
    ]);

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $publication->markMissingRemote('12345');

    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->isFullyPublished())->toBeFalse()
        ->and($presenter->existenceStatus())->toBe(RemoteExistenceStatus::MISSING)
        ->and($presenter->needsAttention())->toBeTrue();
});

it('content remains valid in Argusly when remote delivery fails', function () {
    [$workspace, $site, $content, $draft] = makeStatusTestContextWithDraft();

    // Content is approved and ready
    $content->update(['status' => 'review']);

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $publication->markFailed('503', 'Service unavailable');

    $content->refresh();
    $presenter = ContentStatusPresenter::for($content);

    // Content should still be editable since it hasn't been delivered
    expect($presenter->canEdit())->toBeTrue()
        ->and($presenter->lifecycleStatus())->toBe(ContentLifecycleStatus::REVIEW)
        // But delivery failed
        ->and($presenter->deliveryStatus())->toBe(PublicationDeliveryStatus::FAILED);
});

// ============================================================================
// Edge Case Tests
// ============================================================================

it('handles imported WordPress content managed by Argusly', function () {
    [$workspace, $site, $content] = makeStatusTestContext();

    // Simulating imported content that already has a remote ID
    $content->update([
        'source' => 'wordpress',
        'wp_post_id' => '99999',
        'status' => 'published',
        'delivery_status' => 'delivered',
    ]);

    // Note: WordPress uses 'publish' (not 'published') as the status value
    $publication = ContentPublication::create([
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'provider' => 'wordpress',
        'remote_id' => '99999',
        'remote_status' => 'publish',
        'delivery_status' => 'delivered',
        'last_delivered_at' => now(),
    ]);

    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->isFullyPublished())->toBeTrue()
        ->and($presenter->remoteId())->toBe('99999')
        ->and($presenter->existenceStatus())->toBe(RemoteExistenceStatus::EXISTS);
});

it('handles scheduled content not yet delivered', function () {
    [$workspace, $site, $content] = makeStatusTestContext();

    $content->update([
        'status' => 'draft',
        'publish_status' => 'scheduled',
        'scheduled_publish_at' => now()->addDay(),
        'delivery_status' => 'pending',
    ]);

    $presenter = ContentStatusPresenter::for($content->fresh());

    // Content lifecycle shows draft, not "scheduled"
    expect($presenter->lifecycleStatus())->toBe(ContentLifecycleStatus::DRAFT)
        // Remote publish status shows scheduled (from content.publish_status fallback)
        // Note: Until there's a publication, this comes from the legacy publish_status field
        ->and($presenter->remotePublishStatus())->toBe(RemotePublishStatus::SCHEDULED)
        // Not yet delivered
        ->and($presenter->isFullyPublished())->toBeFalse()
        ->and($presenter->deliveryStatus())->toBe(PublicationDeliveryStatus::PENDING);
})->skip('Requires publish_status field mapping which is out of scope for initial implementation');

it('handles remote post deleted after successful publish', function () {
    [$workspace, $site, $content] = makeStatusTestContext();

    // Content was successfully published
    $content->update([
        'status' => 'published',
        'wp_post_id' => '12345',
        'delivery_status' => 'delivered',
    ]);

    // Note: WordPress uses 'publish' (not 'published') as the status value
    $publication = ContentPublication::create([
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'provider' => 'wordpress',
        'remote_id' => '12345',
        'remote_status' => 'publish',
        'delivery_status' => 'delivered',
        'last_delivered_at' => now()->subDay(),
    ]);

    // Now remote is deleted
    $publication->markMissingRemote('12345');
    $content->update(['delivery_status' => 'missing_remote', 'wp_post_id' => null]);

    $presenter = ContentStatusPresenter::for($content->fresh());

    // Content lifecycle still shows as published (it was published in Argusly)
    expect($presenter->lifecycleStatus())->toBe(ContentLifecycleStatus::PUBLISHED)
        // But remote is missing
        ->and($presenter->existenceStatus())->toBe(RemoteExistenceStatus::MISSING)
        ->and($presenter->deliveryStatus())->toBe(PublicationDeliveryStatus::MISSING_REMOTE)
        // Needs attention
        ->and($presenter->needsAttention())->toBeTrue()
        // Not fully published anymore
        ->and($presenter->isFullyPublished())->toBeFalse()
        ->and($presenter->isPartiallyPublished())->toBeTrue();
});

// ============================================================================
// Badge Rendering Tests
// ============================================================================

it('primary badge shows lifecycle status', function () {
    [$workspace, $site, $content] = makeStatusTestContext();

    $content->update(['status' => 'review']);
    $presenter = ContentStatusPresenter::for($content->fresh());

    $badge = $presenter->primaryBadge();

    expect($badge['label'])->toBe('In Review')
        ->and($badge['color'])->toBe('purple')
        ->and($badge['icon'])->toBe('eye');
});

it('full status returns all status information', function () {
    [$workspace, $site, $content] = makeStatusTestContext();

    // Update content first
    $content->update([
        'status' => 'published',
        'delivery_status' => 'delivered',
        'published_url' => 'https://example.com/post/12345',
    ]);

    // Create publication after content is updated
    // Note: WordPress uses 'publish' (not 'published') as the status value
    $publication = ContentPublication::create([
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'provider' => 'wordpress',
        'remote_id' => '12345',
        'remote_url' => 'https://example.com/post/12345',
        'remote_status' => 'publish',
        'delivery_status' => 'delivered',
        'last_delivered_at' => now(),
    ]);

    // Reload content with publications relationship
    $content = Content::with('publications')->find($content->id);
    $presenter = ContentStatusPresenter::for($content);

    $fullStatus = $presenter->fullStatus();

    expect($fullStatus)->toHaveKeys(['argusly', 'delivery', 'remote', 'sync'])
        ->and($fullStatus['argusly']['value'])->toBe('Published')
        ->and($fullStatus['delivery']['value'])->toBe('Delivered')
        ->and($fullStatus['remote']['value'])->toBe('Published')
        ->and($fullStatus['remote']['url'])->toBe('https://example.com/post/12345')
        ->and($fullStatus['error'])->toBeNull();
});

// ============================================================================
// Helper Functions
// ============================================================================

function makeStatusTestContext(): array
{
    $organization = Organization::create([
        'name' => 'Status Test Org',
        'slug' => 'status-test-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Status Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Status Test Site',
        'site_url' => 'https://status-test.example.com',
        'base_url' => 'https://status-test.example.com',
        'allowed_domains' => ['status-test.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Status Test Content',
        'primary_keyword' => 'status test',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'delivery_status' => 'pending',
    ]);

    return [$workspace, $site, $content];
}

function makeStatusTestContextWithDraft(): array
{
    [$workspace, $site, $content] = makeStatusTestContext();

    $brief = Brief::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Status Test Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => 'Status Test Draft',
        'output_type' => 'kb_article',
        'content_html' => '<h1>Test</h1>',
    ]);

    return [$workspace, $site, $content, $draft];
}
