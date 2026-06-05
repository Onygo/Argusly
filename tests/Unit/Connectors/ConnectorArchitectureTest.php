<?php

/**
 * Phase 4B Connector Architecture Tests
 *
 * Tests for the connector abstraction layer and Laravel connector implementation:
 * - ConnectorCapabilities value object
 * - ConnectorRegistry resolution
 * - Result value objects
 * - LaravelConnector behavior
 */

use App\Contracts\Connectors\ConnectorContract;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Organization;
use App\Models\Workspace;
use App\Support\Connectors\ConnectorCapabilities;
use App\Support\Connectors\ConnectorRegistry;
use App\Support\Connectors\LaravelConnector;
use App\Support\Connectors\Results\HealthCheckResult;
use App\Support\Connectors\Results\PublicationResult;
use App\Support\Connectors\Results\VerificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// =========================================================================
// Test Helpers
// =========================================================================

function createTestWorkspaceForConnectors(): array
{
    $organization = Organization::create([
        'name' => 'Test Org',
        'slug' => 'test-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Test Site',
        'site_url' => 'https://test-' . Str::random(6) . '.example.com',
        'allowed_domains' => ['test.example.com'],
        'is_active' => true,
    ]);

    return [$organization, $workspace, $site];
}

// =========================================================================
// ConnectorCapabilities Tests
// =========================================================================

describe('ConnectorCapabilities - Factory Methods', function () {
    it('creates empty capabilities with make()', function () {
        $caps = ConnectorCapabilities::make();

        expect($caps->supportsCreate)->toBeFalse()
            ->and($caps->supportsUpdate)->toBeFalse()
            ->and($caps->supportsDelete)->toBeFalse();
    });

    it('creates full capabilities with all features enabled', function () {
        $caps = ConnectorCapabilities::full();

        expect($caps->supportsCreate)->toBeTrue()
            ->and($caps->supportsUpdate)->toBeTrue()
            ->and($caps->supportsDelete)->toBeTrue()
            ->and($caps->supportsScheduling)->toBeTrue()
            ->and($caps->supportsVerification)->toBeTrue()
            ->and($caps->supportsFeaturedImage)->toBeTrue()
            ->and($caps->supportsCategories)->toBeTrue()
            ->and($caps->supportsTags)->toBeTrue()
            ->and($caps->supportsSeoFields)->toBeTrue();
    });

    it('creates read-only capabilities', function () {
        $caps = ConnectorCapabilities::readOnly();

        expect($caps->supportsCreate)->toBeFalse()
            ->and($caps->supportsVerification)->toBeTrue();
    });

    it('creates WordPress-specific capabilities', function () {
        $caps = ConnectorCapabilities::wordpress();

        expect($caps->supportsCreate)->toBeTrue()
            ->and($caps->supportsScheduling)->toBeTrue()
            ->and($caps->supportsVerification)->toBeTrue()
            ->and($caps->isAsyncOnly)->toBeFalse()
            ->and($caps->supportedContentTypes)->toContain('post', 'page');
    });

    it('creates Laravel-specific capabilities', function () {
        $caps = ConnectorCapabilities::laravel();

        expect($caps->supportsCreate)->toBeTrue()
            ->and($caps->supportsUpdate)->toBeTrue()
            ->and($caps->supportsScheduling)->toBeFalse()
            ->and($caps->supportsVerification)->toBeTrue()
            ->and($caps->isAsyncOnly)->toBeFalse()
            ->and($caps->supportedContentTypes)->toContain('article');
    });
});

describe('ConnectorCapabilities - Builder Methods', function () {
    it('can add individual capabilities via builder', function () {
        $caps = ConnectorCapabilities::make()
            ->withCreate()
            ->withUpdate()
            ->withFeaturedImage();

        expect($caps->supportsCreate)->toBeTrue()
            ->and($caps->supportsUpdate)->toBeTrue()
            ->and($caps->supportsFeaturedImage)->toBeTrue()
            ->and($caps->supportsDelete)->toBeFalse();
    });

    it('can set content types', function () {
        $caps = ConnectorCapabilities::make()
            ->withContentTypes(['post', 'page', 'custom_type']);

        expect($caps->supportedContentTypes)->toBe(['post', 'page', 'custom_type']);
    });
});

describe('ConnectorCapabilities - Query Methods', function () {
    it('reports capabilities correctly', function () {
        $caps = ConnectorCapabilities::make()
            ->withCreate()
            ->withVerification();

        expect($caps->canPublish())->toBeTrue()
            ->and($caps->canUpdate())->toBeFalse()
            ->and($caps->canVerify())->toBeTrue();
    });

    it('checks content type support', function () {
        $caps = ConnectorCapabilities::wordpress();

        expect($caps->supportsContentType('post'))->toBeTrue()
            ->and($caps->supportsContentType('page'))->toBeTrue()
            ->and($caps->supportsContentType('unknown'))->toBeFalse();
    });

    it('converts to array', function () {
        $caps = ConnectorCapabilities::laravel();
        $array = $caps->toArray();

        expect($array)->toBeArray()
            ->and($array['supportsCreate'])->toBeTrue()
            ->and($array['supportsScheduling'])->toBeFalse()
            ->and($array['isAsyncOnly'])->toBeFalse();
    });
});

// =========================================================================
// PublicationResult Tests
// =========================================================================

describe('PublicationResult - Factory Methods', function () {
    it('creates success result with remote details', function () {
        $result = PublicationResult::success(
            remoteId: '12345',
            remoteUrl: 'https://example.com/post/12345',
            remoteType: 'post',
            remoteStatus: 'published',
        );

        expect($result->isSuccess())->toBeTrue()
            ->and($result->isFailure())->toBeFalse()
            ->and($result->remoteId)->toBe('12345')
            ->and($result->remoteUrl)->toBe('https://example.com/post/12345')
            ->and($result->remoteStatus)->toBe('published');
    });

    it('creates failure result with error details', function () {
        $result = PublicationResult::failure(
            errorCode: 'AUTH_FAILED',
            errorMessage: 'Invalid API key',
            retryable: false,
            httpStatus: 401,
        );

        expect($result->isSuccess())->toBeFalse()
            ->and($result->isFailure())->toBeTrue()
            ->and($result->errorCode)->toBe('AUTH_FAILED')
            ->and($result->errorMessage)->toBe('Invalid API key')
            ->and($result->retryable)->toBeFalse()
            ->and($result->canRetry())->toBeFalse();
    });

    it('creates skipped result', function () {
        $result = PublicationResult::skipped(
            reason: 'Content already up-to-date',
            remoteId: '12345',
        );

        expect($result->isSuccess())->toBeTrue()
            ->and($result->isSkipped())->toBeTrue()
            ->and($result->remoteId)->toBe('12345');
    });
});

describe('PublicationResult - Query Methods', function () {
    it('reports retry capability correctly', function () {
        $retryable = PublicationResult::failure('ERROR', 'Timeout', retryable: true);
        $notRetryable = PublicationResult::failure('ERROR', 'Auth failed', retryable: false);

        expect($retryable->canRetry())->toBeTrue()
            ->and($notRetryable->canRetry())->toBeFalse();
    });

    it('checks for remote ID presence', function () {
        $withId = PublicationResult::success(remoteId: '123');
        $withoutId = PublicationResult::success();

        expect($withId->hasRemoteId())->toBeTrue()
            ->and($withoutId->hasRemoteId())->toBeFalse();
    });

    it('formats error details', function () {
        $result = PublicationResult::failure(
            errorCode: 'SYNC_FAILED',
            errorMessage: 'Connection timeout',
            httpStatus: 504,
        );

        expect($result->errorDetails())->toContain('SYNC_FAILED')
            ->and($result->errorDetails())->toContain('Connection timeout')
            ->and($result->errorDetails())->toContain('504');
    });
});

// =========================================================================
// VerificationResult Tests
// =========================================================================

describe('VerificationResult - Status Methods', function () {
    it('creates exists result', function () {
        $result = VerificationResult::exists(
            remoteStatus: 'published',
            remoteUrl: 'https://example.com/post/123',
        );

        expect($result->isSuccess())->toBeTrue()
            ->and($result->doesExist())->toBeTrue()
            ->and($result->isHealthy())->toBeTrue()
            ->and($result->isGone())->toBeFalse();
    });

    it('creates missing result', function () {
        $result = VerificationResult::missing(httpStatus: 404);

        expect($result->isSuccess())->toBeTrue()
            ->and($result->isMissing())->toBeTrue()
            ->and($result->isGone())->toBeTrue()
            ->and($result->isHealthy())->toBeFalse();
    });

    it('creates trashed result', function () {
        $result = VerificationResult::trashed();

        expect($result->isTrashed())->toBeTrue()
            ->and($result->isGone())->toBeTrue()
            ->and($result->remoteStatus)->toBe('trash');
    });

    it('creates error result', function () {
        $result = VerificationResult::error(
            errorCode: 'NETWORK_ERROR',
            errorMessage: 'Connection refused',
        );

        expect($result->isSuccess())->toBeFalse()
            ->and($result->isError())->toBeTrue();
    });

    it('creates unknown result for unsupported verification', function () {
        $result = VerificationResult::unknown('Verification not supported');

        expect($result->isSuccess())->toBeTrue()
            ->and($result->status)->toBe(VerificationResult::STATUS_UNKNOWN);
    });
});

// =========================================================================
// HealthCheckResult Tests
// =========================================================================

describe('HealthCheckResult - Status Methods', function () {
    it('creates healthy result', function () {
        $result = HealthCheckResult::healthy(
            message: 'All systems operational',
            httpStatus: 200,
            latencyMs: 150.5,
        );

        expect($result->ok)->toBeTrue()
            ->and($result->isHealthy())->toBeTrue()
            ->and($result->isOperational())->toBeTrue()
            ->and($result->statusLabel())->toBe('Healthy');
    });

    it('creates degraded result', function () {
        $result = HealthCheckResult::degraded(
            message: 'High latency detected',
            latencyMs: 5000.0,
        );

        expect($result->ok)->toBeTrue()
            ->and($result->isDegraded())->toBeTrue()
            ->and($result->isOperational())->toBeTrue();
    });

    it('creates unhealthy result', function () {
        $result = HealthCheckResult::unhealthy(
            message: 'Database connection failed',
            httpStatus: 503,
        );

        expect($result->ok)->toBeFalse()
            ->and($result->isUnhealthy())->toBeTrue()
            ->and($result->isOperational())->toBeFalse();
    });

    it('creates from HTTP response', function () {
        $result = HealthCheckResult::fromHttpResponse(
            successful: true,
            httpStatus: 200,
            responseBody: ['ok' => true, 'message' => 'Healthy'],
            latencyMs: 100.0,
        );

        expect($result->ok)->toBeTrue()
            ->and($result->isHealthy())->toBeTrue()
            ->and($result->message)->toBe('Healthy');
    });

    it('creates from exception', function () {
        $exception = new RuntimeException('Connection timed out');
        $result = HealthCheckResult::fromException($exception);

        expect($result->ok)->toBeFalse()
            ->and($result->isUnhealthy())->toBeTrue()
            ->and($result->message)->toBe('Connection timed out');
    });
});

// =========================================================================
// ConnectorRegistry Tests
// =========================================================================

describe('ConnectorRegistry - Registration', function () {
    it('registers and resolves connectors', function () {
        $registry = new ConnectorRegistry();
        $connector = app(LaravelConnector::class);

        $registry->register($connector);

        expect($registry->has('laravel'))->toBeTrue()
            ->and($registry->resolve('laravel'))->toBe($connector);
    });

    it('throws on unknown connector type', function () {
        $registry = new ConnectorRegistry();

        expect(fn () => $registry->resolve('unknown'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('lists all registered types', function () {
        $registry = new ConnectorRegistry();
        $registry->register(app(LaravelConnector::class));

        expect($registry->types())->toContain('laravel');
    });

    it('gets capabilities for all connectors', function () {
        $registry = new ConnectorRegistry();
        $registry->register(app(LaravelConnector::class));

        $capabilities = $registry->allCapabilities();

        expect($capabilities)->toHaveKey('laravel')
            ->and($capabilities['laravel'])->toBeInstanceOf(ConnectorCapabilities::class);
    });
});

// =========================================================================
// LaravelConnector Tests
// =========================================================================

describe('LaravelConnector - Contract Implementation', function () {
    it('implements ConnectorContract', function () {
        $connector = app(LaravelConnector::class);

        expect($connector)->toBeInstanceOf(ConnectorContract::class);
    });

    it('returns laravel as type', function () {
        $connector = app(LaravelConnector::class);

        expect($connector->type())->toBe(ContentPublication::PROVIDER_LARAVEL);
    });

    it('returns Laravel capabilities', function () {
        $connector = app(LaravelConnector::class);
        $caps = $connector->capabilities();

        expect($caps->supportsCreate)->toBeTrue()
            ->and($caps->supportsUpdate)->toBeTrue()
            ->and($caps->supportsDelete)->toBeTrue()
            ->and($caps->supportsScheduling)->toBeFalse()
            ->and($caps->supportsVerification)->toBeTrue()
            ->and($caps->isAsyncOnly)->toBeFalse();
    });

    it('returns unknown for verification (not supported)', function () {
        [$org, $workspace, $site] = createTestWorkspaceForConnectors();

        $destination = ContentDestination::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test Laravel Destination',
            'type' => 'laravel',
            'status' => 'active',
        ]);

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Content',
        ]);

        $publication = ContentPublication::create([
            'content_id' => $content->id,
            'destination_id' => $destination->id,
            'client_site_id' => $site->id,
            'provider' => 'laravel',
            'delivery_status' => 'delivered',
        ]);

        $connector = app(LaravelConnector::class);
        $result = $connector->verify($publication, $destination);

        expect($result->status)->toBe(VerificationResult::STATUS_UNKNOWN)
            ->and($result->isSuccess())->toBeTrue();
    });

    it('maps fields from content', function () {
        [$org, $workspace, $site] = createTestWorkspaceForConnectors();

        $content = Content::create([
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Test Article Title',
            'seo_title' => 'SEO Title',
            'seo_meta_description' => 'SEO Description',
        ]);

        $connector = app(LaravelConnector::class);
        $fields = $connector->mapFields($content);

        expect($fields)->toHaveKey('id')
            ->and($fields['title'])->toBe('Test Article Title')
            ->and($fields['seo_title'])->toBe('SEO Title')
            ->and($fields['seo_description'])->toBe('SEO Description');
    });
});

// =========================================================================
// Integration Tests
// =========================================================================

describe('Connector Registry - Container Integration', function () {
    it('resolves from container as singleton', function () {
        $registry1 = app(ConnectorRegistry::class);
        $registry2 = app(ConnectorRegistry::class);

        expect($registry1)->toBe($registry2);
    });

    it('has Laravel connector registered', function () {
        $registry = app(ConnectorRegistry::class);

        expect($registry->has('laravel'))->toBeTrue();
    });
});
