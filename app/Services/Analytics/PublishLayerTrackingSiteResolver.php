<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the current request host to an AnalyticsSite for first-party tracking injection.
 *
 * Only returns a site when:
 * - The domain is an internal verified domain (from config)
 * - Analytics is enabled on the site
 * - The site is verified (either internally or via meta tag)
 *
 * Results are cached per request to avoid repeated database lookups.
 */
class PublishLayerTrackingSiteResolver
{
    private const REQUEST_CACHE_KEY = 'pl_tracking_site_resolver';

    /**
     * Check if the given host is an internal verified domain.
     */
    public function isInternalDomain(?string $host = null): bool
    {
        $host = $host ?? $this->getCurrentHost();

        if ($host === '') {
            return false;
        }

        $internalDomains = $this->getInternalVerifiedDomains();

        return in_array(strtolower($host), $internalDomains, true);
    }

    /**
     * Resolve the current request to an AnalyticsSite for tracking injection.
     *
     * Returns null if:
     * - The domain is not an internal verified domain
     * - No matching ClientSite exists
     * - Analytics is not enabled
     * - The site is not verified
     */
    public function resolve(): ?AnalyticsSite
    {
        $requestHash = $this->getRequestHash();
        $cacheKey = self::REQUEST_CACHE_KEY . '.' . $requestHash;

        // Use request-scoped cache to avoid repeated lookups within the same request
        if (Cache::store('array')->has($cacheKey)) {
            return Cache::store('array')->get($cacheKey);
        }

        $host = $this->getCurrentHost();

        if (! $this->isInternalDomain($host)) {
            Cache::store('array')->put($cacheKey, null);

            return null;
        }

        $site = $this->findAnalyticsSiteForHost($host);

        // Auto-verify internal domain if not already verified
        if ($site && $site->is_enabled && ! $site->isVerified()) {
            $site->markInternallyVerified($host);
            $site->refresh();
        }

        // Only return if enabled and verified
        if (! $site || ! $site->is_enabled || ! $site->isVerified()) {
            Cache::store('array')->put($cacheKey, null);

            return null;
        }

        Cache::store('array')->put($cacheKey, $site);

        return $site;
    }

    /**
     * Get the tracking script URL.
     */
    public function getTrackingScriptUrl(): string
    {
        $baseUrl = rtrim($this->getTrackingBaseUrl(), '/');
        $version = (string) config('publishlayer.tracking_script_version', '1.1.0');

        return $baseUrl . '/pl.js?v=' . rawurlencode($version);
    }

    /**
     * Get the tracking configuration for the current site.
     *
     * @return array{siteKey: string, engagedAfterSeconds: int, readThroughScrollPercent: int, readThroughFallbackSeconds: int}|null
     */
    public function getTrackingConfig(): ?array
    {
        $site = $this->resolve();

        if (! $site) {
            return null;
        }

        return [
            'siteKey' => $site->public_key,
            'engagedAfterSeconds' => (int) config('analytics.tracking.engaged_after_seconds', 10),
            'readThroughScrollPercent' => (int) config('analytics.tracking.read_through_scroll_percent', 75),
            'readThroughFallbackSeconds' => (int) config('analytics.tracking.read_through_fallback_seconds', 20),
        ];
    }

    /**
     * Check if tracking should be injected for the current request.
     */
    public function shouldInjectTracking(): bool
    {
        // Check environment restrictions
        if (app()->environment('testing') && ! config('publishlayer.analytics.allow_tracking_in_testing', false)) {
            return false;
        }

        if (app()->environment('local') && ! config('publishlayer.analytics.allow_tracking_on_local', false)) {
            return false;
        }

        return $this->resolve() !== null;
    }

    /**
     * Get the list of internal verified domains from config.
     *
     * @return array<int, string>
     */
    public function getInternalVerifiedDomains(): array
    {
        $domains = config('publishlayer.analytics.internal_verified_domains', []);

        if (! is_array($domains)) {
            return [];
        }

        return array_map('strtolower', array_filter(array_map('trim', $domains)));
    }

    private function getCurrentHost(): string
    {
        return strtolower(trim((string) request()->getHost()));
    }

    private function getTrackingBaseUrl(): string
    {
        $configuredUrl = trim((string) config('publishlayer.tracking_url', ''));

        if ($configuredUrl !== '') {
            return $configuredUrl;
        }

        $baseDomain = config('domains.base', 'publishlayer.local');
        $scheme = request()->secure() ? 'https' : 'http';

        return "{$scheme}://track.{$baseDomain}";
    }

    private function getRequestHash(): string
    {
        return md5($this->getCurrentHost() . '|' . spl_object_id(request()));
    }

    private function findAnalyticsSiteForHost(string $host): ?AnalyticsSite
    {
        // Look for a ClientSite with this domain in site_url or allowed_domains
        $clientSite = ClientSite::query()
            ->whereHas('analyticsSite')
            ->where(function ($query) use ($host) {
                // Match by site_url domain
                $query->where('site_url', 'like', '%://' . $host . '%')
                    ->orWhere('site_url', 'like', '%://' . $host)
                    ->orWhere('base_url', 'like', '%://' . $host . '%')
                    ->orWhere('base_url', 'like', '%://' . $host);
            })
            ->first();

        if ($clientSite) {
            return $clientSite->analyticsSite;
        }

        // Also check allowed_domains JSON column
        $clientSite = ClientSite::query()
            ->whereHas('analyticsSite')
            ->whereJsonContains('allowed_domains', $host)
            ->first();

        return $clientSite?->analyticsSite;
    }
}
