<?php

use App\Services\Analytics\AnalyticsSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Public Page Analytics Tracking', function () {
    it('does not render tracking when disabled', function () {
        $service = app(AnalyticsSettingsService::class);
        $service->updateSettings([
            'analytics_enabled' => false,
        ]);

        $this->get(route('landing'))
            ->assertOk()
            ->assertDontSee('googletagmanager.com/gtag/js')
            ->assertDontSee('googletagmanager.com/gtm.js');
    });

    it('does not render tracking in testing environment by default', function () {
        config(['publishlayer.analytics.allow_tracking_in_testing' => false]);

        $service = app(AnalyticsSettingsService::class);
        $service->updateSettings([
            'analytics_enabled' => true,
            'analytics_provider' => 'google_analytics_gtag',
            'analytics_measurement_id' => 'G-TEST12345',
        ]);

        $this->get(route('landing'))
            ->assertOk()
            ->assertDontSee('G-TEST12345');
    });

    it('renders GA4 tracking on public pages when enabled and environment allows', function () {
        config(['publishlayer.analytics.allow_tracking_in_testing' => true]);

        $service = app(AnalyticsSettingsService::class);
        $service->updateSettings([
            'analytics_enabled' => true,
            'analytics_provider' => 'google_analytics_gtag',
            'analytics_measurement_id' => 'G-PUBTEST123',
        ]);

        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('G-PUBTEST123')
            ->assertSee('googletagmanager.com/gtag/js');
    });

    it('renders GTM tracking on public pages when enabled', function () {
        config(['publishlayer.analytics.allow_tracking_in_testing' => true]);

        $service = app(AnalyticsSettingsService::class);
        $service->updateSettings([
            'analytics_enabled' => true,
            'analytics_provider' => 'google_tag_manager',
            'analytics_container_id' => 'GTM-PUBTEST',
        ]);

        $response = $this->get(route('landing'));

        $response->assertOk()
            ->assertSee('GTM-PUBTEST')
            ->assertSee('googletagmanager.com/gtm.js');
    });

    it('renders custom script on public pages when enabled', function () {
        config(['publishlayer.analytics.allow_tracking_in_testing' => true]);

        $customScript = '<script>window.customTracking = true;</script>';

        $service = app(AnalyticsSettingsService::class);
        $service->updateSettings([
            'analytics_enabled' => true,
            'analytics_provider' => 'custom_head_script',
            'analytics_custom_head_script' => $customScript,
        ]);

        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('window.customTracking = true', false);
    });

    it('does not render empty tracking when measurement ID is missing', function () {
        config(['publishlayer.analytics.allow_tracking_in_testing' => true]);

        $service = app(AnalyticsSettingsService::class);
        $service->updateSettings([
            'analytics_enabled' => true,
            'analytics_provider' => 'google_analytics_gtag',
            'analytics_measurement_id' => '',
        ]);

        $this->get(route('landing'))
            ->assertOk()
            ->assertDontSee('googletagmanager.com/gtag/js');
    });
});
