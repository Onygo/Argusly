<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDeliveryEvent;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\ContentPublishTarget;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ============================================================================
// Publication Model Tests
// ============================================================================

it('creates a publication record for content and destination', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    $publication = ContentPublication::resolveForDelivery(
        $content->id,
        null,
        $site->id,
        ContentPublication::PROVIDER_WORDPRESS
    );

    expect($publication)->toBeInstanceOf(ContentPublication::class)
        ->and($publication->content_id)->toBe($content->id)
        ->and($publication->client_site_id)->toBe($site->id)
        ->and($publication->provider)->toBe('wordpress')
        ->and($publication->delivery_status)->toBe('pending')
        ->and($publication->hasRemoteId())->toBeFalse();
});

it('reuses existing publication record on subsequent resolves', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    $first = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $second = ContentPublication::resolveForDelivery($content->id, null, $site->id);

    expect($first->id)->toBe($second->id);
});

it('reuses a single existing publication when a later resolve provides the actual locale', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    $initial = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $resolved = ContentPublication::resolveForDelivery($content->id, null, $site->id, ContentPublication::PROVIDER_WORDPRESS, 'nl');

    expect($resolved->id)->toBe($initial->id)
        ->and($resolved->getRawOriginal('locale'))->toBe('nl')
        ->and(ContentPublication::query()->where('content_id', $content->id)->count())->toBe(1);
});

it('marks publication as delivered and records remote id', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $publication->markDelivered('12345', 'https://example.com/post/12345');

    expect($publication->delivery_status)->toBe('delivered')
        ->and($publication->remote_id)->toBe('12345')
        ->and($publication->remote_url)->toBe('https://example.com/post/12345')
        ->and($publication->remote_status)->toBe('published')
        ->and($publication->last_delivered_at)->not->toBeNull()
        ->and($publication->hasRemoteId())->toBeTrue()
        ->and($publication->getWpPostId())->toBe('12345');
});

it('marks publication as failed with error details', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $publication->markFailed('401', 'WordPress rejected the request as unauthorized.');

    expect($publication->delivery_status)->toBe('failed')
        ->and($publication->last_error_code)->toBe('401')
        ->and($publication->last_error_message)->toBe('WordPress rejected the request as unauthorized.')
        ->and($publication->last_error_at)->not->toBeNull();
});

it('marks publication as missing remote and preserves previous remote ids', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $publication->markDelivered('12345', 'https://example.com/post/12345');
    $publication->markMissingRemote('12345');

    expect($publication->delivery_status)->toBe('missing_remote')
        ->and($publication->remote_id)->toBeNull()
        ->and($publication->remote_status)->toBeNull()
        ->and($publication->meta['previous_remote_ids'])->toContain('12345');
});

it('supports content relationship and helper methods', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);

    expect($publication->content->id)->toBe($content->id)
        ->and($publication->isWordPress())->toBeTrue()
        ->and($publication->isPending())->toBeTrue()
        ->and($publication->isDelivered())->toBeFalse()
        ->and($publication->isFailed())->toBeFalse();
});

// ============================================================================
// Delivery Event Tests
// ============================================================================

it('records create remote event on successful delivery', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);

    $event = ContentDeliveryEvent::recordCreate(
        $publication,
        ['title' => 'Test Post'],
        ['wp_post_id' => '12345'],
        201,
        'correlation-123',
        150
    );

    expect($event)->toBeInstanceOf(ContentDeliveryEvent::class)
        ->and($event->event_type)->toBe('create_remote')
        ->and($event->status)->toBe('success')
        ->and($event->http_status)->toBe(201)
        ->and($event->correlation_id)->toBe('correlation-123')
        ->and($event->duration_ms)->toBe(150)
        ->and($event->message)->toBe('Remote resource created successfully.')
        ->and($event->publication->id)->toBe($publication->id);
});

it('records update remote event', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);

    $event = ContentDeliveryEvent::recordUpdate(
        $publication,
        ['title' => 'Updated Post'],
        ['wp_post_id' => '12345'],
        200,
        'correlation-456'
    );

    expect($event->event_type)->toBe('update_remote')
        ->and($event->status)->toBe('success');
});

it('records recreate remote event with previous id', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);

    $event = ContentDeliveryEvent::recordRecreate(
        $publication,
        '99999',
        ['title' => 'Recreated Post'],
        ['wp_post_id' => '12346'],
        201,
        'correlation-789'
    );

    expect($event->event_type)->toBe('recreate_remote')
        ->and($event->status)->toBe('success')
        ->and($event->message)->toContain('99999');
});

it('records verify remote event', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);

    $successEvent = ContentDeliveryEvent::recordVerify(
        $publication,
        true,
        ['exists' => true, 'wp_post_id' => '12345'],
        200
    );

    expect($successEvent->event_type)->toBe('verify_remote')
        ->and($successEvent->status)->toBe('success')
        ->and($successEvent->message)->toBe('Remote resource verified to exist.');

    $failEvent = ContentDeliveryEvent::recordVerify(
        $publication,
        false,
        ['exists' => false],
        404
    );

    expect($failEvent->status)->toBe('failed')
        ->and($failEvent->message)->toBe('Remote resource not found.');
});

it('records failure event with error details', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);

    $event = ContentDeliveryEvent::recordFailure(
        $publication,
        'Connection timeout after 30 seconds',
        '504',
        ['title' => 'Test Post'],
        [],
        504,
        'correlation-error'
    );

    expect($event->event_type)->toBe('fail_remote')
        ->and($event->status)->toBe('failed')
        ->and($event->message)->toContain('[504]')
        ->and($event->message)->toContain('Connection timeout')
        ->and($event->http_status)->toBe(504);
});

it('truncates large payloads in events', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $largeContent = str_repeat('x', 5000);

    $event = ContentDeliveryEvent::recordCreate(
        $publication,
        ['content' => $largeContent],
        [],
        201
    );

    // The content field should be truncated
    $storedRequest = $event->request_payload_json;
    expect(strlen($storedRequest['content']))->toBeLessThan(2000);
});

it('maintains delivery event history for a publication', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);

    ContentDeliveryEvent::recordCreate($publication, [], [], 201);
    ContentDeliveryEvent::recordUpdate($publication, [], [], 200);
    ContentDeliveryEvent::recordVerify($publication, true, [], 200);
    ContentDeliveryEvent::recordFailure($publication, 'Temporary error', null, [], [], 503);
    ContentDeliveryEvent::recordUpdate($publication, [], [], 200);

    $events = $publication->deliveryEvents()->get();

    expect($events)->toHaveCount(5);

    $eventTypes = $events->pluck('event_type')->toArray();
    expect($eventTypes)->toContain('create_remote')
        ->toContain('update_remote')
        ->toContain('verify_remote')
        ->toContain('fail_remote');
});

// ============================================================================
// Content Model Integration Tests
// ============================================================================

it('content model can resolve publications via relationship', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    ContentPublication::resolveForDelivery($content->id, null, $site->id);

    $content->refresh();

    expect($content->publications()->count())->toBe(1);
    expect($content->publicationForDestination(null, $site->id))->not->toBeNull();
});

it('content model resolvePublication creates lazily', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    expect(ContentPublication::query()->where('content_id', $content->id)->exists())->toBeFalse();

    $publication = $content->resolvePublication(null, $site->id);

    expect($publication)->toBeInstanceOf(ContentPublication::class);
    expect(ContentPublication::query()->where('content_id', $content->id)->exists())->toBeTrue();
});

// ============================================================================
// Legacy Migration Tests
// ============================================================================

it('migrates wordpress post id from content to publication', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    // Simulate legacy data: content has wp_post_id but no publication
    $content->update(['wp_post_id' => '54321']);

    // Run the migration query manually (simulating the migration)
    if (! ContentPublication::query()->where('content_id', $content->id)->exists()) {
        ContentPublication::create([
            'content_id' => $content->id,
            'client_site_id' => $content->client_site_id,
            'provider' => 'wordpress',
            'remote_id' => $content->wp_post_id,
            'remote_type' => 'post',
            'delivery_status' => 'delivered',
            'meta' => ['migrated_from' => 'contents'],
        ]);
    }

    $publication = ContentPublication::query()->where('content_id', $content->id)->first();

    expect($publication)->not->toBeNull()
        ->and($publication->remote_id)->toBe('54321')
        ->and($publication->getWpPostId())->toBe('54321')
        ->and($publication->delivery_status)->toBe('delivered')
        ->and($publication->meta['migrated_from'])->toBe('contents');
});

it('migrates wordpress post id from publish target to publication', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    // Create legacy ContentPublishTarget with wp_post_id
    $target = ContentPublishTarget::create([
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'target_type' => 'wp',
        'wp_post_id' => '67890',
        'sync_status' => 'synced',
        'last_synced_at' => now(),
        'meta' => ['published_url' => 'https://example.com/post/67890'],
    ]);

    // Simulate migration from publish target
    if (! ContentPublication::query()->where('content_id', $content->id)->exists()) {
        $targetMeta = is_array($target->meta) ? $target->meta : [];
        ContentPublication::create([
            'content_id' => $content->id,
            'client_site_id' => $target->client_site_id,
            'provider' => 'wordpress',
            'remote_id' => $target->wp_post_id,
            'remote_type' => 'post',
            'remote_url' => $targetMeta['published_url'] ?? null,
            'delivery_status' => 'delivered',
            'last_delivered_at' => $target->last_synced_at,
            'meta' => ['migrated_from' => 'content_publish_targets'],
        ]);
    }

    $publication = ContentPublication::query()->where('content_id', $content->id)->first();

    expect($publication)->not->toBeNull()
        ->and($publication->remote_id)->toBe('67890')
        ->and($publication->remote_url)->toBe('https://example.com/post/67890')
        ->and($publication->meta['migrated_from'])->toBe('content_publish_targets');
});

it('existing content remains deliverable after migration', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    // Setup: content has been delivered to WordPress in the old system
    $content->update([
        'wp_post_id' => '11111',
        'delivery_status' => 'delivered',
        'publish_status' => 'published',
    ]);

    ContentPublishTarget::create([
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'target_type' => 'wp',
        'wp_post_id' => '11111',
        'sync_status' => 'synced',
    ]);

    // Now create publication record (as migration would)
    $publication = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $publication->markDelivered('11111', 'https://example.com/post/11111');

    // Verify the content is still accessible via all paths
    expect($content->wp_post_id)->toBe('11111')
        ->and($content->publications()->first()->getWpPostId())->toBe('11111')
        ->and($content->publishTargets()->first()->wp_post_id)->toBe('11111');

    // The publication should be the canonical source
    $canonicalWpPostId = $content->publications()
        ->where('provider', 'wordpress')
        ->first()
        ?->getWpPostId();

    expect($canonicalWpPostId)->toBe('11111');
});

// ============================================================================
// Scope Tests
// ============================================================================

it('publication scopes work correctly', function () {
    [$workspace, $site, $content] = makePublicationTestContext();

    // Create a delivered publication
    $pub1 = ContentPublication::resolveForDelivery($content->id, null, $site->id);
    $pub1->markDelivered('123', null);

    // Create a second content with failed publication
    $content2 = Content::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Second Content',
        'primary_keyword' => 'test keyword 2',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wordpress',
    ]);
    $pub2 = ContentPublication::resolveForDelivery($content2->id, null, $site->id);
    $pub2->markFailed('500', 'Server error');

    // Test scopes
    expect(ContentPublication::delivered()->count())->toBe(1)
        ->and(ContentPublication::failed()->count())->toBe(1)
        ->and(ContentPublication::forProvider('wordpress')->count())->toBe(2)
        ->and(ContentPublication::forClientSite($site->id)->count())->toBe(2)
        ->and(ContentPublication::forContent($content->id)->count())->toBe(1);
});

// ============================================================================
// Helper Functions
// ============================================================================

function makePublicationTestContext(): array
{
    $organization = Organization::create([
        'name' => 'Publication Test Org',
        'slug' => 'publication-test-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Publication Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Publication Test Site',
        'site_url' => 'https://publication-test.example.com',
        'base_url' => 'https://publication-test.example.com',
        'allowed_domains' => ['publication-test.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Publication Test Content',
        'primary_keyword' => 'test keyword',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wordpress',
        'delivery_status' => 'pending',
    ]);

    return [$workspace, $site, $content];
}
