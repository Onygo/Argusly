<?php

namespace App\Services\Analytics;

use App\Models\User;
use App\Services\SiteSettingsService;

class AnalyticsSettingsService
{
    private const SETTINGS_KEY = 'analytics';

    public const PROVIDER_GOOGLE_ANALYTICS = 'google_analytics_gtag';
    public const PROVIDER_GOOGLE_TAG_MANAGER = 'google_tag_manager';
    public const PROVIDER_CUSTOM_SCRIPT = 'custom_head_script';

    public const PROVIDERS = [
        self::PROVIDER_GOOGLE_ANALYTICS => 'Google Analytics (gtag.js)',
        self::PROVIDER_GOOGLE_TAG_MANAGER => 'Google Tag Manager',
        self::PROVIDER_CUSTOM_SCRIPT => 'Custom head script',
    ];

    public function __construct(
        private readonly SiteSettingsService $siteSettings
    ) {}

    public function getSettings(): array
    {
        $defaults = [
            'analytics_provider' => null,
            'analytics_measurement_id' => null,
            'analytics_container_id' => null,
            'analytics_custom_head_script' => null,
            'analytics_enabled' => false,
            'analytics_public_only' => true,
            'analytics_updated_by' => null,
            'analytics_updated_at' => null,
        ];

        $stored = (array) $this->siteSettings->get(self::SETTINGS_KEY, []);

        return array_merge($defaults, $stored);
    }

    public function updateSettings(array $data, ?User $updatedBy = null): void
    {
        $settings = $this->getSettings();

        $settings['analytics_provider'] = $data['analytics_provider'] ?? null;
        $settings['analytics_measurement_id'] = $data['analytics_measurement_id'] ?? null;
        $settings['analytics_container_id'] = $data['analytics_container_id'] ?? null;
        $settings['analytics_custom_head_script'] = $data['analytics_custom_head_script'] ?? null;
        $settings['analytics_enabled'] = (bool) ($data['analytics_enabled'] ?? false);
        $settings['analytics_public_only'] = (bool) ($data['analytics_public_only'] ?? true);
        $settings['analytics_updated_by'] = $updatedBy?->id;
        $settings['analytics_updated_at'] = now()->toIso8601String();

        $this->siteSettings->put(self::SETTINGS_KEY, $settings);
    }

    public function isEnabled(): bool
    {
        if (! $this->isTrackingAllowedInEnvironment()) {
            return false;
        }

        $settings = $this->getSettings();

        return (bool) ($settings['analytics_enabled'] ?? false);
    }

    public function isPublicOnly(): bool
    {
        $settings = $this->getSettings();

        return (bool) ($settings['analytics_public_only'] ?? true);
    }

    public function getProvider(): ?string
    {
        $settings = $this->getSettings();

        return $settings['analytics_provider'] ?? null;
    }

    public function getMeasurementId(): ?string
    {
        $settings = $this->getSettings();

        return $settings['analytics_measurement_id'] ?? null;
    }

    public function getContainerId(): ?string
    {
        $settings = $this->getSettings();

        return $settings['analytics_container_id'] ?? null;
    }

    public function getCustomScript(): ?string
    {
        $settings = $this->getSettings();

        return $settings['analytics_custom_head_script'] ?? null;
    }

    public function shouldRenderTracking(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $provider = $this->getProvider();

        if ($provider === null) {
            return false;
        }

        return match ($provider) {
            self::PROVIDER_GOOGLE_ANALYTICS => $this->getMeasurementId() !== null && $this->getMeasurementId() !== '',
            self::PROVIDER_GOOGLE_TAG_MANAGER => $this->getContainerId() !== null && $this->getContainerId() !== '',
            self::PROVIDER_CUSTOM_SCRIPT => $this->getCustomScript() !== null && trim($this->getCustomScript()) !== '',
            default => false,
        };
    }

    public function isTrackingAllowedInEnvironment(): bool
    {
        $environment = app()->environment();

        if ($environment === 'testing') {
            return (bool) config('publishlayer.analytics.allow_tracking_in_testing', false);
        }

        if ($environment === 'local') {
            return (bool) config('publishlayer.analytics.allow_tracking_on_local', false);
        }

        if (in_array($environment, ['staging', 'acceptance'], true)) {
            return (bool) config('publishlayer.analytics.allow_tracking_on_staging', true);
        }

        return true;
    }

    public static function isValidMeasurementId(?string $id): bool
    {
        if ($id === null || $id === '') {
            return true; // Optional field
        }

        return (bool) preg_match('/^G-[A-Z0-9]{8,12}$/i', $id);
    }

    public static function isValidContainerId(?string $id): bool
    {
        if ($id === null || $id === '') {
            return true; // Optional field
        }

        return (bool) preg_match('/^GTM-[A-Z0-9]{6,10}$/i', $id);
    }
}
