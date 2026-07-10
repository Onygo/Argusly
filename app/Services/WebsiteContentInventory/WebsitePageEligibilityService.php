<?php

namespace App\Services\WebsiteContentInventory;

use App\Models\Content;
use App\Models\MonitoredPage;
use Illuminate\Support\Str;

class WebsitePageEligibilityService
{
    public function evaluate(MonitoredPage $page, ?Content $content = null): WebsitePageEligibilityResult
    {
        $page->loadMissing('latestSnapshot');

        $reasons = [];
        $signals = [];
        $candidateUrl = $this->candidateUrl($page, $content);
        $normalizedUrl = $this->normalizeUrl($candidateUrl);
        $urlHash = $normalizedUrl ? $this->urlHash($normalizedUrl) : null;
        $override = $this->reviewOverride($page, $content);

        $signals['review_override'] = $override;
        $signals['source_type'] = (string) $page->source_type;
        $signals['page_type'] = $page->page_type;
        $signals['candidate_url'] = $candidateUrl;
        $signals['normalized_url'] = $normalizedUrl;

        if ($override === 'excluded') {
            return new WebsitePageEligibilityResult(false, false, ['review_excluded'], $signals, $normalizedUrl, $urlHash);
        }

        if (! $normalizedUrl || ! $this->isPublicUrl($normalizedUrl)) {
            $reasons[] = 'not_public';
        }

        if ($normalizedUrl && $this->isExcludedUrl($normalizedUrl)) {
            $reasons[] = 'excluded_path';
        }

        $httpStatus = $this->httpStatus($page);
        $signals['http_status'] = $httpStatus;
        if (! $this->httpStatusEligible($httpStatus)) {
            $reasons[] = $httpStatus === null ? 'http_status_missing' : 'http_status_ineligible';
        }

        $indexabilityStatus = strtolower(trim((string) $page->indexability_status));
        $signals['indexability_status'] = $indexabilityStatus;
        if (! $this->indexabilityEligible($indexabilityStatus)) {
            $reasons[] = 'indexability_ineligible';
        }

        $robotsAllowed = $this->robotsAllowed($page);
        $signals['robots_allowed'] = $robotsAllowed;
        if (! $this->robotsEligible($robotsAllowed)) {
            $reasons[] = $robotsAllowed === null ? 'robots_unknown' : 'robots_disallowed';
        }

        $campaignPageTypeEligible = $this->campaignPageTypeEligible($page->page_type);
        $signals['campaign_page_type_eligible'] = $campaignPageTypeEligible;
        if (! $campaignPageTypeEligible) {
            $reasons[] = 'campaign_page_type_ineligible';
        }

        if ($override === 'included') {
            $reasons = array_values(array_diff($reasons, [
                'excluded_path',
                'indexability_ineligible',
                'robots_unknown',
                'robots_disallowed',
                'campaign_page_type_ineligible',
            ]));
        }

        $eligible = $reasons === [];
        $campaignEligible = $eligible
            && $campaignPageTypeEligible
            && (bool) config('website_content_inventory.campaign_defaults.campaign_eligible_by_default', true);

        if ($override === 'campaign_ready' && $normalizedUrl && $this->isPublicUrl($normalizedUrl)) {
            $eligible = ! in_array('http_status_ineligible', $reasons, true);
            $campaignEligible = $eligible;
            $reasons = $eligible ? [] : $reasons;
        }

        return new WebsitePageEligibilityResult(
            eligible: $eligible,
            campaignEligible: $campaignEligible,
            reasons: array_values(array_unique($reasons)),
            signals: $signals,
            normalizedUrl: $normalizedUrl,
            urlHash: $urlHash,
        );
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function evaluateUrl(string $url, ?string $pageType = null, array $metadata = []): WebsitePageEligibilityResult
    {
        $normalizedUrl = $this->normalizeUrl($url);
        $page = new MonitoredPage([
            'canonical_url' => $normalizedUrl ?: $url,
            'first_seen_url' => $normalizedUrl ?: $url,
            'final_url' => $normalizedUrl ?: $url,
            'source_type' => (string) ($metadata['source_type'] ?? 'website_content_inventory'),
            'page_type' => $pageType,
            'metadata_json' => $metadata,
        ]);

        if ($normalizedUrl) {
            $page->canonical_url_hash = $this->urlHash($normalizedUrl);
            $page->first_seen_url_hash = $this->urlHash($normalizedUrl);
            $page->final_url_hash = $this->urlHash($normalizedUrl);
            $page->domain = (string) parse_url($normalizedUrl, PHP_URL_HOST);
            $page->path = (string) parse_url($normalizedUrl, PHP_URL_PATH);
        }

        return $this->evaluate($page);
    }

    public function normalizeUrl(?string $url): ?string
    {
        $candidate = trim((string) $url);
        if ($candidate === '') {
            return null;
        }

        if (str_contains($candidate, '://') && ! preg_match('#^https?://#i', $candidate)) {
            return null;
        }

        if (! preg_match('#^https?://#i', $candidate)) {
            $candidate = 'https://'.ltrim($candidate, '/');
        }

        $parts = parse_url($candidate);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        $host = trim($host, '[]');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = $this->normalizeQuery((string) ($parts['query'] ?? ''));

        $normalized = $scheme.'://'.$host;
        if ($port !== null && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $normalized .= ':'.$port;
        }

        $normalized .= $path;

        if ($query !== '') {
            $normalized .= '?'.$query;
        }

        return strlen($normalized) > 2048 ? null : $normalized;
    }

    public function urlHash(string $normalizedUrl): string
    {
        return hash('sha256', $normalizedUrl);
    }

    public function isExcludedUrl(string $url): bool
    {
        $path = strtolower($this->normalizePath((string) (parse_url($url, PHP_URL_PATH) ?: '/')));

        foreach ((array) config('website_content_inventory.excluded_paths', []) as $excludedPath) {
            $excluded = strtolower($this->normalizePath((string) $excludedPath));

            if ($path === $excluded || ($excluded !== '/' && str_starts_with($path.'/', rtrim($excluded, '/').'/'))) {
                return true;
            }
        }

        $normalized = strtolower($url);
        foreach ((array) config('website_content_inventory.excluded_page_patterns', []) as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern !== '' && Str::is($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function candidateUrl(MonitoredPage $page, ?Content $content): string
    {
        foreach ([
            $content?->canonical_url,
            $content?->seo_canonical,
            $content?->published_url,
            $page->canonical_url,
            $page->final_url,
            $page->first_seen_url,
        ] as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = $this->decodeSafePathCharacters($path);

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    private function normalizeQuery(string $query): string
    {
        $query = trim($query);
        if ($query === '') {
            return '';
        }

        $pairs = [];
        parse_str($query, $pairs);

        $allowlist = collect((array) config('website_content_inventory.query_parameter_allowlist', []))
            ->map(fn (mixed $key): string => strtolower(trim((string) $key)))
            ->filter()
            ->values()
            ->all();

        $pairs = collect($pairs)
            ->filter(fn (mixed $value, string|int $key): bool => in_array(strtolower((string) $key), $allowlist, true))
            ->all();

        if ($pairs === []) {
            return '';
        }

        ksort($pairs);

        return http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
    }

    private function isPublicUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        $host = trim($host, '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if ($host === 'localhost' || $host === '0' || str_ends_with($host, '.localhost')) {
            return false;
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    private function httpStatus(MonitoredPage $page): ?int
    {
        $snapshotStatus = $page->latestSnapshot?->http_status;
        if ($snapshotStatus !== null) {
            return (int) $snapshotStatus;
        }

        $metadataStatus = data_get($page->metadata_json, 'http_status');

        return is_numeric($metadataStatus) ? (int) $metadataStatus : null;
    }

    private function httpStatusEligible(?int $httpStatus): bool
    {
        if ($httpStatus === null) {
            return (bool) config('website_content_inventory.eligibility.allow_unfetched_pages', true)
                && ! (bool) config('website_content_inventory.eligibility.require_successful_http_status', false);
        }

        $eligible = array_map('intval', (array) config('website_content_inventory.eligibility.eligible_http_statuses', [200]));

        return in_array($httpStatus, $eligible, true);
    }

    private function indexabilityEligible(string $indexabilityStatus): bool
    {
        $ineligible = collect((array) config('website_content_inventory.eligibility.ineligible_indexability_statuses', []))
            ->map(fn (mixed $status): string => strtolower(trim((string) $status)))
            ->all();

        if (in_array($indexabilityStatus, $ineligible, true)) {
            return false;
        }

        $eligible = collect((array) config('website_content_inventory.eligibility.eligible_indexability_statuses', []))
            ->map(fn (mixed $status): string => strtolower(trim((string) $status)))
            ->all();

        return $eligible === [] || in_array($indexabilityStatus, $eligible, true);
    }

    private function robotsAllowed(MonitoredPage $page): ?bool
    {
        foreach ([
            data_get($page->metadata_json, 'robots.allowed'),
            data_get($page->metadata_json, 'robots_allowed'),
            data_get($page->latestSnapshot?->metadata_json, 'robots.allowed'),
            data_get($page->latestSnapshot?->metadata_json, 'robots_allowed'),
        ] as $value) {
            if ($value !== null) {
                return (bool) $value;
            }
        }

        return null;
    }

    private function robotsEligible(?bool $robotsAllowed): bool
    {
        if (! (bool) config('website_content_inventory.eligibility.respect_robots', true)) {
            return true;
        }

        if ($robotsAllowed === null) {
            return ! (bool) config('website_content_inventory.eligibility.require_robots_allowed', false);
        }

        return $robotsAllowed;
    }

    private function campaignPageTypeEligible(?string $pageType): bool
    {
        $normalized = strtolower(trim((string) $pageType));

        $ineligible = collect((array) config('website_content_inventory.campaign_defaults.ineligible_page_types', []))
            ->map(fn (mixed $type): string => strtolower(trim((string) $type)))
            ->all();

        if (in_array($normalized, $ineligible, true)) {
            return false;
        }

        $eligible = collect((array) config('website_content_inventory.campaign_defaults.eligible_page_types', []))
            ->map(fn (mixed $type): string => strtolower(trim((string) $type)))
            ->all();

        return $eligible === [] || in_array($normalized, $eligible, true);
    }

    private function reviewOverride(MonitoredPage $page, ?Content $content): ?string
    {
        $value = data_get($content?->inventory_metadata, 'eligibility.review_override')
            ?? data_get($page->metadata_json, 'inventory.review_override')
            ?? data_get($page->metadata_json, 'review_override');

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'exclude', 'excluded', 'ineligible', 'not_eligible' => 'excluded',
            'include', 'included', 'eligible' => 'included',
            'campaign_ready', 'campaign-ready', 'ready' => 'campaign_ready',
            default => null,
        };
    }

    private function decodeSafePathCharacters(string $path): string
    {
        return preg_replace_callback('/%[0-9a-fA-F]{2}/', static function (array $matches): string {
            $hex = strtoupper((string) $matches[0]);
            $char = chr(hexdec(substr($hex, 1)));

            if (preg_match('/[A-Za-z0-9\\-._~]/', $char) === 1) {
                return $char;
            }

            return $hex;
        }, $path) ?? $path;
    }
}
