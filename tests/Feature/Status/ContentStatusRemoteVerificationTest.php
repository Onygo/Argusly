<?php

use App\Enums\ContentDestinationType;
use App\Enums\ContentLifecycleStatus;
use App\Enums\PublicationDeliveryStatus;
use App\Enums\RemoteExistenceStatus;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Organization;
use App\Models\Workspace;
use App\View\Presenters\ContentStatusPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Remote existence verification requirements', function () {
    it('Laravel destinations do not require strict remote verification', function () {
        expect(ContentDestinationType::LARAVEL->requiresStrictRemoteVerification())->toBeFalse()
            ->and(ContentDestinationType::LARAVEL->isNativeDestination())->toBeTrue();
    });

    it('WordPress destinations require strict remote verification', function () {
        expect(ContentDestinationType::WORDPRESS->requiresStrictRemoteVerification())->toBeTrue()
            ->and(ContentDestinationType::WORDPRESS->isNativeDestination())->toBeFalse();
    });

    it('API destinations require strict remote verification', function () {
        expect(ContentDestinationType::API->requiresStrictRemoteVerification())->toBeTrue()
            ->and(ContentDestinationType::API->isNativeDestination())->toBeFalse();
    });
});

describe('Remote existence health checks', function () {
    it('EXISTS is healthy for all destination types', function () {
        $status = RemoteExistenceStatus::EXISTS;

        expect($status->isHealthyFor(ContentDestinationType::LARAVEL))->toBeTrue()
            ->and($status->isHealthyFor(ContentDestinationType::WORDPRESS))->toBeTrue()
            ->and($status->isHealthyFor(ContentDestinationType::API))->toBeTrue()
            ->and($status->isHealthy())->toBeTrue();
    });

    it('UNKNOWN is healthy for Laravel but not WordPress', function () {
        $status = RemoteExistenceStatus::UNKNOWN;

        expect($status->isHealthyFor(ContentDestinationType::LARAVEL))->toBeTrue()
            ->and($status->isHealthyFor(ContentDestinationType::WORDPRESS))->toBeFalse()
            ->and($status->isHealthyFor(ContentDestinationType::API))->toBeFalse()
            ->and($status->isHealthy())->toBeFalse();
    });

    it('MISSING is never healthy', function () {
        $status = RemoteExistenceStatus::MISSING;

        expect($status->isHealthyFor(ContentDestinationType::LARAVEL))->toBeFalse()
            ->and($status->isHealthyFor(ContentDestinationType::WORDPRESS))->toBeFalse()
            ->and($status->isHealthyFor(ContentDestinationType::API))->toBeFalse()
            ->and($status->isHealthy())->toBeFalse();
    });
});

describe('Laravel content with UNKNOWN remote state', function () {
    it('can be fully published with UNKNOWN remote state', function () {
        [$workspace, $destination, $content] = makeLaravelTestContext();

        // Mark as published and delivered
        $content->update([
            'status' => 'published',
            'delivery_status' => 'delivered',
        ]);

        // Create successful publication with no remote verification (no remote_id or URL = UNKNOWN)
        $publication = ContentPublication::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'content_id' => $content->id,
            'destination_id' => $destination->id,
            'provider' => 'laravel',
            'delivery_status' => PublicationDeliveryStatus::DELIVERED->value,
            // No remote_id or remote_url - stays UNKNOWN
            'last_delivered_at' => now(),
        ]);

        // Remote existence not verified - stays UNKNOWN
        $content = $content->fresh();
        $content->load('publications');
        $loadedPub = $content->publications->first();

        // Verify publication doesn't have remote_id
        expect($loadedPub)->not->toBeNull()
            ->and($loadedPub->remote_id)->toBeNull();

        $presenter = ContentStatusPresenter::for($content);

        expect($presenter->lifecycleStatus())->toBe(ContentLifecycleStatus::PUBLISHED)
            ->and($presenter->deliveryStatus())->toBe(PublicationDeliveryStatus::DELIVERED)
            ->and($presenter->existenceStatus())->toBe(RemoteExistenceStatus::UNKNOWN)
            ->and($presenter->isFullyPublished())->toBeTrue(); // Should be true!
    });

    it('does not show verify remote action when successfully delivered', function () {
        [$workspace, $destination, $content] = makeLaravelTestContext();

        $content->update([
            'status' => 'published',
            'delivery_status' => 'delivered',
        ]);

        $publication = ContentPublication::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'content_id' => $content->id,
            'destination_id' => $destination->id,
            'provider' => 'laravel',
            'delivery_status' => PublicationDeliveryStatus::DELIVERED->value,
            // No remote_id or remote_url - stays UNKNOWN
            'last_delivered_at' => now(),
        ]);

        $presenter = ContentStatusPresenter::for($content->fresh());

        expect($presenter->canVerifyRemote())->toBeFalse(); // No need to verify
    });

    it('shows verify remote action when there is a delivery problem', function () {
        [$workspace, $destination, $content] = makeLaravelTestContext();

        $content->update([
            'status' => 'published',
            'delivery_status' => 'delivered',
        ]);

        $publication = ContentPublication::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'content_id' => $content->id,
            'destination_id' => $destination->id,
            'provider' => 'laravel',
            'delivery_status' => PublicationDeliveryStatus::FAILED->value,
            'remote_url' => 'https://example.com/articles/test',
            'last_error_code' => '500',
            'last_error_message' => 'Server error',
            'last_error_at' => now(),
        ]);

        $presenter = ContentStatusPresenter::for($content->fresh());

        expect($presenter->canVerifyRemote())->toBeTrue(); // Debugging tool when there's a problem
    });

    it('shows verify remote action when remote is gone', function () {
        [$workspace, $destination, $content] = makeLaravelTestContext();

        $content->update([
            'status' => 'published',
            'delivery_status' => 'delivered',
        ]);

        $publication = ContentPublication::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'content_id' => $content->id,
            'destination_id' => $destination->id,
            'provider' => 'laravel',
            'delivery_status' => PublicationDeliveryStatus::MISSING_REMOTE->value,
            'remote_id' => (string) $content->id,
            'remote_url' => 'https://example.com/articles/test',
        ]);

        $presenter = ContentStatusPresenter::for($content->fresh());

        expect($presenter->existenceStatus())->toBe(RemoteExistenceStatus::MISSING)
            ->and($presenter->canVerifyRemote())->toBeTrue(); // Show verify when remote is gone
    });
});

describe('WordPress content with UNKNOWN remote state', function () {
    it('cannot be fully published with UNKNOWN remote state', function () {
        [$workspace, $site, $content] = makeWordPressTestContext();

        $content->update([
            'status' => 'published',
            'delivery_status' => 'delivered',
        ]);

        $publication = ContentPublication::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => 'wordpress',
            'delivery_status' => PublicationDeliveryStatus::DELIVERED->value,
            // No remote_id or remote_url - stays UNKNOWN
            'last_delivered_at' => now(),
        ]);

        // Remote existence not verified yet - stays UNKNOWN
        $presenter = ContentStatusPresenter::for($content->fresh());

        expect($presenter->lifecycleStatus())->toBe(ContentLifecycleStatus::PUBLISHED)
            ->and($presenter->deliveryStatus())->toBe(PublicationDeliveryStatus::DELIVERED)
            ->and($presenter->existenceStatus())->toBe(RemoteExistenceStatus::UNKNOWN)
            ->and($presenter->isFullyPublished())->toBeFalse() // Requires verification
            ->and($presenter->canVerifyRemote())->toBeTrue(); // Always available for WordPress
    });

    it('is fully published when remote existence is verified as EXISTS', function () {
        [$workspace, $site, $content] = makeWordPressTestContext();

        $content->update([
            'status' => 'published',
            'delivery_status' => 'delivered',
        ]);

        $publication = ContentPublication::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => 'wordpress',
            'delivery_status' => PublicationDeliveryStatus::DELIVERED->value,
            'remote_id' => '12345',
            'remote_url' => 'https://example.com/post/12345',
            'remote_status' => 'published',
            'last_delivered_at' => now(),
            'last_verified_at' => now(),
        ]);

        $presenter = ContentStatusPresenter::for($content->fresh());

        expect($presenter->existenceStatus())->toBe(RemoteExistenceStatus::EXISTS)
            ->and($presenter->isFullyPublished())->toBeTrue();
    });

    it('is partially published when remote is missing', function () {
        [$workspace, $site, $content] = makeWordPressTestContext();

        $content->update([
            'status' => 'published',
            'delivery_status' => 'delivered',
        ]);

        $publication = ContentPublication::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'provider' => 'wordpress',
            'delivery_status' => PublicationDeliveryStatus::MISSING_REMOTE->value,
            'remote_id' => '12345',
        ]);

        $presenter = ContentStatusPresenter::for($content->fresh());

        expect($presenter->existenceStatus())->toBe(RemoteExistenceStatus::MISSING)
            ->and($presenter->isPartiallyPublished())->toBeTrue()
            ->and($presenter->isFullyPublished())->toBeFalse();
    });
});

describe('Connector capabilities', function () {
    it('Laravel connector does not require strict verification', function () {
        $caps = \App\Support\Connectors\ConnectorCapabilities::laravel();

        expect($caps->supportsVerification)->toBeTrue() // Still supports it
            ->and($caps->requiresStrictVerification)->toBeFalse(); // But not required
    });

    it('WordPress connector requires strict verification', function () {
        $caps = \App\Support\Connectors\ConnectorCapabilities::wordpress();

        expect($caps->supportsVerification)->toBeTrue()
            ->and($caps->requiresStrictVerification)->toBeTrue();
    });
});

// ============================================================================
// Test Helper Functions
// ============================================================================

function makeLaravelTestContext(): array
{
    $organization = Organization::create([
        'name' => 'Laravel Test Org',
        'slug' => 'laravel-test-org-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Laravel Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $destination = ContentDestination::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Laravel Test Destination',
        'config' => [
            'laravel_connector' => [
                'enabled' => true,
                'sync_url' => 'https://example.com/api/argusly/sync',
                'api_key' => encrypt('test-key'),
                'site_id' => 'test-site',
            ],
        ],
    ]);

    $content = Content::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'content_destination_id' => $destination->id,
        'title' => 'Laravel Test Content',
        'primary_keyword' => 'laravel test',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'delivery_status' => 'pending',
    ]);

    return [$workspace, $destination, $content];
}

function makeWordPressTestContext(): array
{
    $organization = Organization::create([
        'name' => 'WordPress Test Org',
        'slug' => 'wp-test-org-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'WordPress Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'WordPress Test Site',
        'site_url' => 'https://wp-test.example.com',
        'base_url' => 'https://wp-test.example.com',
        'allowed_domains' => ['wp-test.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'WordPress Test Content',
        'primary_keyword' => 'wordpress test',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'delivery_status' => 'pending',
    ]);

    return [$workspace, $site, $content];
}
