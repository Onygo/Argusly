<?php

use App\Models\Organization;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Analytics\AnalyticsSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $organization = Organization::query()->create([
        'name' => 'Test Org',
        'slug' => 'test-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $this->superadmin = User::query()->create([
        'name' => 'Super Admin',
        'email' => 'superadmin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'superadmin',
    ]);

    $this->admin = User::query()->create([
        'name' => 'Admin User',
        'email' => 'admin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);

    $this->regularUser = User::query()->create([
        'name' => 'Regular User',
        'email' => 'user+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'member',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
        'admin_role' => null,
    ]);
});

describe('Admin Analytics Settings Page', function () {
    it('allows superadmin to view analytics settings page', function () {
        $this->actingAs($this->superadmin)
            ->get(route('admin.analytics.index'))
            ->assertOk()
            ->assertSee('Analytics Settings')
            ->assertSee('Enable analytics')
            ->assertSee('Tracking Provider');
    });

    it('blocks non-superadmin from viewing analytics settings page', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.analytics.index'))
            ->assertForbidden();
    });

    it('blocks regular users from viewing analytics settings page', function () {
        $this->actingAs($this->regularUser)
            ->get(route('admin.analytics.index'))
            ->assertForbidden();
    });

    it('requires authentication to view analytics settings', function () {
        $this->get(route('admin.analytics.index'))
            ->assertRedirect();
    });
});

describe('Analytics Settings Updates', function () {
    it('allows superadmin to save valid GA4 measurement ID', function () {
        $this->actingAs($this->superadmin)
            ->post(route('admin.analytics.update'), [
                'analytics_enabled' => '1',
                'analytics_public_only' => '1',
                'analytics_provider' => 'google_analytics_gtag',
                'analytics_measurement_id' => 'G-ABCD1234EF',
            ])
            ->assertRedirect(route('admin.analytics.index'))
            ->assertSessionHas('status', 'Analytics settings updated successfully.');

        $settings = app(AnalyticsSettingsService::class)->getSettings();
        expect($settings['analytics_enabled'])->toBeTrue()
            ->and($settings['analytics_provider'])->toBe('google_analytics_gtag')
            ->and($settings['analytics_measurement_id'])->toBe('G-ABCD1234EF');
    });

    it('allows superadmin to save valid GTM container ID', function () {
        $this->actingAs($this->superadmin)
            ->post(route('admin.analytics.update'), [
                'analytics_enabled' => '1',
                'analytics_public_only' => '1',
                'analytics_provider' => 'google_tag_manager',
                'analytics_container_id' => 'GTM-ABC123',
            ])
            ->assertRedirect(route('admin.analytics.index'));

        $settings = app(AnalyticsSettingsService::class)->getSettings();
        expect($settings['analytics_provider'])->toBe('google_tag_manager')
            ->and($settings['analytics_container_id'])->toBe('GTM-ABC123');
    });

    it('rejects invalid GA4 measurement ID format', function () {
        $this->actingAs($this->superadmin)
            ->post(route('admin.analytics.update'), [
                'analytics_enabled' => '1',
                'analytics_provider' => 'google_analytics_gtag',
                'analytics_measurement_id' => 'invalid-id',
            ])
            ->assertSessionHasErrors('analytics_measurement_id');
    });

    it('rejects invalid GTM container ID format', function () {
        $this->actingAs($this->superadmin)
            ->post(route('admin.analytics.update'), [
                'analytics_enabled' => '1',
                'analytics_provider' => 'google_tag_manager',
                'analytics_container_id' => 'invalid-id',
            ])
            ->assertSessionHasErrors('analytics_container_id');
    });

    it('allows superadmin to save custom head script', function () {
        $customScript = '<script>console.log("test");</script>';

        $this->actingAs($this->superadmin)
            ->post(route('admin.analytics.update'), [
                'analytics_enabled' => '1',
                'analytics_provider' => 'custom_head_script',
                'analytics_custom_head_script' => $customScript,
            ])
            ->assertRedirect(route('admin.analytics.index'));

        $settings = app(AnalyticsSettingsService::class)->getSettings();
        expect($settings['analytics_custom_head_script'])->toBe($customScript);
    });

    it('blocks non-superadmin from updating analytics settings', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.analytics.update'), [
                'analytics_enabled' => '1',
                'analytics_provider' => 'google_analytics_gtag',
                'analytics_measurement_id' => 'G-ABCD1234EF',
            ])
            ->assertForbidden();
    });

    it('records the user who updated settings', function () {
        $this->actingAs($this->superadmin)
            ->post(route('admin.analytics.update'), [
                'analytics_enabled' => '1',
                'analytics_provider' => 'google_analytics_gtag',
                'analytics_measurement_id' => 'G-ABCD1234EF',
            ]);

        $settings = app(AnalyticsSettingsService::class)->getSettings();
        expect($settings['analytics_updated_by'])->toBe($this->superadmin->id)
            ->and($settings['analytics_updated_at'])->not->toBeNull();
    });
});

describe('Analytics Settings Service', function () {
    it('returns default values when no settings exist', function () {
        $service = app(AnalyticsSettingsService::class);
        $settings = $service->getSettings();

        expect($settings['analytics_enabled'])->toBeFalse()
            ->and($settings['analytics_public_only'])->toBeTrue()
            ->and($settings['analytics_provider'])->toBeNull();
    });

    it('validates GA4 measurement ID format correctly', function () {
        expect(AnalyticsSettingsService::isValidMeasurementId('G-ABCD1234EF'))->toBeTrue()
            ->and(AnalyticsSettingsService::isValidMeasurementId('G-12345678'))->toBeTrue()
            ->and(AnalyticsSettingsService::isValidMeasurementId('invalid'))->toBeFalse()
            ->and(AnalyticsSettingsService::isValidMeasurementId('UA-12345-1'))->toBeFalse()
            ->and(AnalyticsSettingsService::isValidMeasurementId(''))->toBeTrue()
            ->and(AnalyticsSettingsService::isValidMeasurementId(null))->toBeTrue();
    });

    it('validates GTM container ID format correctly', function () {
        expect(AnalyticsSettingsService::isValidContainerId('GTM-ABC123'))->toBeTrue()
            ->and(AnalyticsSettingsService::isValidContainerId('GTM-ABCDEFGH'))->toBeTrue()
            ->and(AnalyticsSettingsService::isValidContainerId('invalid'))->toBeFalse()
            ->and(AnalyticsSettingsService::isValidContainerId('G-ABCD1234EF'))->toBeFalse()
            ->and(AnalyticsSettingsService::isValidContainerId(''))->toBeTrue()
            ->and(AnalyticsSettingsService::isValidContainerId(null))->toBeTrue();
    });

    it('shouldRenderTracking returns false when disabled', function () {
        $service = app(AnalyticsSettingsService::class);

        $service->updateSettings([
            'analytics_enabled' => false,
            'analytics_provider' => 'google_analytics_gtag',
            'analytics_measurement_id' => 'G-ABCD1234EF',
        ]);

        expect($service->shouldRenderTracking())->toBeFalse();
    });

    it('shouldRenderTracking returns false when provider is null', function () {
        $service = app(AnalyticsSettingsService::class);

        $service->updateSettings([
            'analytics_enabled' => true,
            'analytics_provider' => null,
        ]);

        expect($service->shouldRenderTracking())->toBeFalse();
    });

    it('shouldRenderTracking returns false when GA4 ID is empty', function () {
        $service = app(AnalyticsSettingsService::class);

        config(['publishlayer.analytics.allow_tracking_in_testing' => true]);

        $service->updateSettings([
            'analytics_enabled' => true,
            'analytics_provider' => 'google_analytics_gtag',
            'analytics_measurement_id' => '',
        ]);

        expect($service->shouldRenderTracking())->toBeFalse();
    });
});

describe('Analytics Rendering', function () {
    it('renders nothing when tracking is disabled', function () {
        $service = app(AnalyticsSettingsService::class);
        $service->updateSettings([
            'analytics_enabled' => false,
        ]);

        $renderer = app(\App\Services\Analytics\AnalyticsRenderer::class);
        $output = $renderer->renderHeadTracking();

        expect((string) $output)->toBe('');
    });

    it('renders GA4 script when enabled with valid ID', function () {
        config(['publishlayer.analytics.allow_tracking_in_testing' => true]);

        $service = app(AnalyticsSettingsService::class);
        $service->updateSettings([
            'analytics_enabled' => true,
            'analytics_provider' => 'google_analytics_gtag',
            'analytics_measurement_id' => 'G-TEST12345',
        ]);

        $renderer = app(\App\Services\Analytics\AnalyticsRenderer::class);
        $output = (string) $renderer->renderHeadTracking();

        expect($output)->toContain('G-TEST12345')
            ->and($output)->toContain('googletagmanager.com/gtag/js')
            ->and($output)->toContain("gtag('config', 'G-TEST12345')");
    });

    it('renders GTM head script when enabled', function () {
        config(['publishlayer.analytics.allow_tracking_in_testing' => true]);

        $service = app(AnalyticsSettingsService::class);
        $service->updateSettings([
            'analytics_enabled' => true,
            'analytics_provider' => 'google_tag_manager',
            'analytics_container_id' => 'GTM-TEST123',
        ]);

        $renderer = app(\App\Services\Analytics\AnalyticsRenderer::class);
        $headOutput = (string) $renderer->renderHeadTracking();
        $bodyOutput = (string) $renderer->renderBodyTracking();

        expect($headOutput)->toContain('GTM-TEST123')
            ->and($headOutput)->toContain('googletagmanager.com/gtm.js')
            ->and($bodyOutput)->toContain('GTM-TEST123')
            ->and($bodyOutput)->toContain('noscript');
    });

    it('renders custom script when provider is custom_head_script', function () {
        config(['publishlayer.analytics.allow_tracking_in_testing' => true]);

        $customScript = '<script>console.log("custom tracking");</script>';

        $service = app(AnalyticsSettingsService::class);
        $service->updateSettings([
            'analytics_enabled' => true,
            'analytics_provider' => 'custom_head_script',
            'analytics_custom_head_script' => $customScript,
        ]);

        $renderer = app(\App\Services\Analytics\AnalyticsRenderer::class);
        $output = (string) $renderer->renderHeadTracking();

        expect($output)->toContain($customScript);
    });
});

describe('Environment-based Tracking', function () {
    it('respects testing environment config', function () {
        config(['publishlayer.analytics.allow_tracking_in_testing' => false]);

        $service = app(AnalyticsSettingsService::class);
        expect($service->isTrackingAllowedInEnvironment())->toBeFalse();

        config(['publishlayer.analytics.allow_tracking_in_testing' => true]);
        expect($service->isTrackingAllowedInEnvironment())->toBeTrue();
    });

    it('returns false for isEnabled when environment blocks tracking', function () {
        config(['publishlayer.analytics.allow_tracking_in_testing' => false]);

        $service = app(AnalyticsSettingsService::class);
        $service->updateSettings([
            'analytics_enabled' => true,
            'analytics_provider' => 'google_analytics_gtag',
            'analytics_measurement_id' => 'G-ABCD1234EF',
        ]);

        expect($service->isEnabled())->toBeFalse();
    });
});
