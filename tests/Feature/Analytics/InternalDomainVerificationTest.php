<?php

use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Analytics\DomainVerificationService;
use App\Services\Analytics\PublishLayerTrackingSiteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('Internal Domain Verification', function () {
    it('auto-verifies an internal domain without requiring meta tag', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['publishlayer.com', 'www.publishlayer.com']);

        [$user, $site, $analyticsSite] = createInternalVerificationContext(siteUrl: 'https://publishlayer.com');

        // No HTTP fake needed - internal domains don't make external requests

        $response = $this->actingAs($user)
            ->post(route('app.sites.analytics.verify', $site));

        $response->assertRedirect(route('app.sites.analytics.show', $site))
            ->assertSessionHas('status', 'Domain verified as first-party internal domain');

        $analyticsSite->refresh();
        expect($analyticsSite->verified_at)->not->toBeNull();
        expect($analyticsSite->isInternallyVerified())->toBeTrue();
        expect($analyticsSite->flags['internally_verified'])->toBeTrue();
        expect($analyticsSite->flags['internal_domain'])->toBe('publishlayer.com');
    });

    it('auto-verifies www subdomain as internal domain', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['publishlayer.com', 'www.publishlayer.com']);

        [$user, $site, $analyticsSite] = createInternalVerificationContext(siteUrl: 'https://www.publishlayer.com');

        $response = $this->actingAs($user)
            ->post(route('app.sites.analytics.verify', $site));

        $response->assertRedirect(route('app.sites.analytics.show', $site))
            ->assertSessionHas('status', 'Domain verified as first-party internal domain');

        $analyticsSite->refresh();
        expect($analyticsSite->isInternallyVerified())->toBeTrue();
    });

    it('requires meta tag verification for external customer domains', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['publishlayer.com']);

        [$user, $site, $analyticsSite] = createInternalVerificationContext(siteUrl: 'https://customer-site.com');

        Http::fake([
            'https://customer-site.com/' => Http::response('<html><head></head></html>', 200),
        ]);

        $response = $this->actingAs($user)
            ->post(route('app.sites.analytics.verify', $site));

        $response->assertRedirect(route('app.sites.analytics.show', $site))
            ->assertSessionHas('error', 'Verification meta tag not found. Add the tag shown below to your site head and retry.');

        expect($analyticsSite->fresh()->verified_at)->toBeNull();
    });

    it('does not allow customer domains to be auto-verified', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['publishlayer.com']);

        $service = app(DomainVerificationService::class);

        expect($service->isInternalVerifiedDomain('publishlayer.com'))->toBeTrue();
        expect($service->isInternalVerifiedDomain('customer-site.com'))->toBeFalse();
        expect($service->isInternalVerifiedDomain('fake-publishlayer.com'))->toBeFalse();
        expect($service->isInternalVerifiedDomain(''))->toBeFalse();
    });

    it('handles case-insensitive domain matching', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['PublishLayer.com']);

        $service = app(DomainVerificationService::class);

        expect($service->isInternalVerifiedDomain('publishlayer.com'))->toBeTrue();
        expect($service->isInternalVerifiedDomain('PUBLISHLAYER.COM'))->toBeTrue();
        expect($service->isInternalVerifiedDomain('PublishLayer.com'))->toBeTrue();
    });
});

describe('PublishLayerTrackingSiteResolver', function () {
    it('resolves internal domain to analytics site', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['publishlayer.com']);

        [$user, $site, $analyticsSite] = createInternalVerificationContext(siteUrl: 'https://publishlayer.com');

        // Simulate request from internal domain
        $this->app['request']->headers->set('HOST', 'publishlayer.com');

        $resolver = app(PublishLayerTrackingSiteResolver::class);

        expect($resolver->isInternalDomain('publishlayer.com'))->toBeTrue();
        expect($resolver->isInternalDomain('customer-site.com'))->toBeFalse();
    });

    it('returns tracking config for verified internal site', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['publishlayer.com']);
        config()->set('analytics.tracking.engaged_after_seconds', 10);
        config()->set('analytics.tracking.read_through_scroll_percent', 75);
        config()->set('analytics.tracking.read_through_fallback_seconds', 20);

        [$user, $site, $analyticsSite] = createInternalVerificationContext(siteUrl: 'https://publishlayer.com');

        // Mark as verified first
        $analyticsSite->markInternallyVerified('publishlayer.com');

        // Simulate request from internal domain
        $this->app['request']->headers->set('HOST', 'publishlayer.com');

        $resolver = app(PublishLayerTrackingSiteResolver::class);
        $config = $resolver->getTrackingConfig();

        expect($config)->not->toBeNull();
        expect($config['siteKey'])->toBe($analyticsSite->public_key);
        expect($config['engagedAfterSeconds'])->toBe(10);
        expect($config['readThroughScrollPercent'])->toBe(75);
        expect($config['readThroughFallbackSeconds'])->toBe(20);
    });

    it('falls back to the track subdomain when no tracking url is configured', function () {
        config()->set('domains.base', 'publishlayer.com');
        config()->set('publishlayer.tracking_url', '');
        config()->set('publishlayer.tracking_script_version', '1.2.1');

        $this->app['request']->headers->set('HOST', 'publishlayer.com');
        $this->app['request']->server->set('HTTPS', 'on');

        $resolver = app(PublishLayerTrackingSiteResolver::class);

        expect($resolver->getTrackingScriptUrl())->toBe('https://track.publishlayer.com/pl.js?v=1.2.1');
    });

    it('returns null for non-internal domain', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['publishlayer.com']);

        [$user, $site, $analyticsSite] = createInternalVerificationContext(siteUrl: 'https://customer-site.com');

        $this->app['request']->headers->set('HOST', 'customer-site.com');

        $resolver = app(PublishLayerTrackingSiteResolver::class);

        expect($resolver->isInternalDomain())->toBeFalse();
        expect($resolver->resolve())->toBeNull();
        expect($resolver->getTrackingConfig())->toBeNull();
    });

    it('returns null when analytics is disabled', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['publishlayer.com']);

        [$user, $site, $analyticsSite] = createInternalVerificationContext(siteUrl: 'https://publishlayer.com');

        $analyticsSite->update(['is_enabled' => false]);

        $this->app['request']->headers->set('HOST', 'publishlayer.com');

        $resolver = app(PublishLayerTrackingSiteResolver::class);

        expect($resolver->resolve())->toBeNull();
    });

    it('does not inject tracking in testing environment by default', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['publishlayer.com']);
        config()->set('publishlayer.analytics.allow_tracking_in_testing', false);

        [$user, $site, $analyticsSite] = createInternalVerificationContext(siteUrl: 'https://publishlayer.com');
        $analyticsSite->markInternallyVerified('publishlayer.com');

        $this->app['request']->headers->set('HOST', 'publishlayer.com');

        $resolver = app(PublishLayerTrackingSiteResolver::class);

        expect($resolver->shouldInjectTracking())->toBeFalse();
    });

    it('injects tracking in testing environment when allowed', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['publishlayer.com']);
        config()->set('publishlayer.analytics.allow_tracking_in_testing', true);

        [$user, $site, $analyticsSite] = createInternalVerificationContext(siteUrl: 'https://publishlayer.com');
        $analyticsSite->markInternallyVerified('publishlayer.com');

        $this->app['request']->headers->set('HOST', 'publishlayer.com');

        $resolver = app(PublishLayerTrackingSiteResolver::class);

        expect($resolver->shouldInjectTracking())->toBeTrue();
    });
});

describe('AnalyticsSite Internal Verification Methods', function () {
    it('correctly identifies internally verified sites', function () {
        [$user, $site, $analyticsSite] = createInternalVerificationContext();

        expect($analyticsSite->isInternallyVerified())->toBeFalse();

        $analyticsSite->markInternallyVerified('test.com');

        expect($analyticsSite->isInternallyVerified())->toBeTrue();
        expect($analyticsSite->flags['internally_verified'])->toBeTrue();
        expect($analyticsSite->flags['internal_domain'])->toBe('test.com');
    });

    it('differentiates internally verified from meta tag verified', function () {
        [$user1, $site1, $internalSite] = createInternalVerificationContext(
            siteUrl: 'https://internal-site.com',
            organizationSlugPrefix: 'internal-org'
        );
        $internalSite->markInternallyVerified('internal-site.com');

        [$user2, $site2, $metaTagSite] = createInternalVerificationContext(
            siteUrl: 'https://metatag-site.com',
            organizationSlugPrefix: 'metatag-org'
        );
        $metaTagSite->markVerified();

        expect($internalSite->isInternallyVerified())->toBeTrue();
        expect($metaTagSite->isInternallyVerified())->toBeFalse();
        expect($metaTagSite->isVerified())->toBeTrue();
    });
});

describe('Analytics UI for Internal Verification', function () {
    it('shows first-party domain label for internally verified site', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['publishlayer.com']);

        [$user, $site, $analyticsSite] = createInternalVerificationContext(siteUrl: 'https://publishlayer.com');
        $analyticsSite->markInternallyVerified('publishlayer.com');

        $response = $this->actingAs($user)
            ->get(route('app.sites.analytics.show', $site));

        $response->assertOk();
        $response->assertSee('Verified via first-party domain');
        $response->assertSee('First-Party Domain Verified');
        $response->assertSee('publishlayer.com');
        $response->assertDontSee('Domain Verification Required');
    });

    it('shows verification instructions for external unverified site', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['publishlayer.com']);

        [$user, $site, $analyticsSite] = createInternalVerificationContext(siteUrl: 'https://customer-site.com');

        $response = $this->actingAs($user)
            ->get(route('app.sites.analytics.show', $site));

        $response->assertOk();
        $response->assertSee('Domain Verification Required');
        $response->assertSee('Add the following meta tag');
        $response->assertSee($analyticsSite->verification_token);
    });

    it('shows simplified tracking section for internally verified site', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['publishlayer.com']);

        [$user, $site, $analyticsSite] = createInternalVerificationContext(siteUrl: 'https://publishlayer.com');
        $analyticsSite->markInternallyVerified('publishlayer.com');

        $response = $this->actingAs($user)
            ->get(route('app.sites.analytics.show', $site));

        $response->assertOk();
        $response->assertSee('Tracking is automatically injected on the marketing site');
        $response->assertSee('No manual installation required');
    });

    it('shows a first-party empty state on learnings for internally verified sites', function () {
        config()->set('publishlayer.analytics.internal_verified_domains', ['publishlayer.com']);

        [$user, $site, $analyticsSite] = createInternalVerificationContext(siteUrl: 'https://publishlayer.com');
        $analyticsSite->markInternallyVerified('publishlayer.com');

        $response = $this->actingAs($user)
            ->get(route('app.sites.learnings.index', $site));

        $response->assertOk();
        $response->assertSee('Tracking is automatically injected for this first-party domain');
        $response->assertSee('View analytics setup');
        $response->assertDontSee('Install tracking script');
    });
});

function createInternalVerificationContext(
    string $siteUrl = 'https://publishlayer.com',
    string $organizationSlugPrefix = 'internal-verification-org'
): array {
    $host = parse_url($siteUrl, PHP_URL_HOST);

    $organization = Organization::query()->create([
        'name' => 'Internal Verification Org',
        'slug' => $organizationSlugPrefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Internal Verification BV',
        'billing_address_line1' => 'Internal Street 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Internal Verification Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'internal-verification-plan'],
        [
            'name' => 'Internal Verification Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Internal Verify Site',
        'site_url' => $siteUrl,
        'base_url' => $siteUrl,
        'allowed_domains' => [$host],
        'is_active' => true,
        'status' => 'connected',
    ]);

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

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $analyticsSite = AnalyticsSite::query()->create([
        'client_site_id' => $site->id,
        'allowed_domains' => [$host],
        'is_enabled' => true,
    ]);

    return [$user, $site, $analyticsSite, $workspace, $organization];
}
