<?php

namespace App\Services\WebsiteContentInventory;

use App\Jobs\PageIntelligence\DiscoverMonitoredSourceUrlsJob;
use App\Models\ClientSite;
use App\Models\MonitoredSource;
use App\Services\PageIntelligence\PageCrawlerSafetyService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class WebsiteSitemapSourceSetupService
{
    public function __construct(private readonly PageCrawlerSafetyService $safety) {}

    /**
     * @param  array{dry_run?:bool,dispatch_discovery?:bool}  $options
     */
    public function ensureForSite(ClientSite $site, array $options = []): SitemapSourceSetupResult
    {
        $site->loadMissing(['workspace', 'analyticsSite']);

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $dispatchDiscovery = (bool) ($options['dispatch_discovery'] ?? config('website_content_inventory.sitemap.dispatch_discovery_after_setup', false));
        $result = new SitemapSourceSetupResult(dryRun: $dryRun);
        $result->sitesProcessed = 1;

        if (! (bool) config('website_content_inventory.sitemap.enabled', true)) {
            $result->sourcesRejected++;
            $result->message('Sitemap setup is disabled.');

            return $result;
        }

        if (! $this->sitePermitted($site)) {
            $result->sourcesRejected++;
            $result->message('Client site is not verified or explicitly permitted for sitemap discovery.');

            return $result;
        }

        $allowedHosts = $this->allowedHosts($site);
        $sitemapUrl = $this->sitemapUrl($site);
        if ($sitemapUrl === null) {
            $result->sourcesRejected++;
            $result->message('Client site does not have a usable base URL.');

            return $result;
        }

        try {
            $sitemapUrl = $this->safety->normalizeAndValidate($sitemapUrl, respectRobots: false);
        } catch (InvalidArgumentException $exception) {
            $result->sourcesRejected++;
            $result->message($exception->getMessage());

            return $result;
        }

        $host = strtolower((string) parse_url($sitemapUrl, PHP_URL_HOST));
        if (! $this->hostAllowed($host, $allowedHosts) && ! $this->crossDomainOverrideAllowed($site)) {
            $result->sourcesRejected++;
            $result->message('Sitemap URL is outside the verified site domain boundary.');

            return $result;
        }

        if ($dryRun) {
            $result->sourcesUnchanged++;
            $result->message('Dry run: sitemap source would be ensured for '.$sitemapUrl);

            return $result;
        }

        $sourceType = (string) config('website_content_inventory.sitemap.source_type', 'xml_sitemap');
        $source = MonitoredSource::query()
            ->where('workspace_id', $site->workspace_id)
            ->where('client_site_id', $site->id)
            ->where('source_type', $sourceType)
            ->where('base_url', $sitemapUrl)
            ->first();

        $payload = [
            'organization_id' => $site->workspace?->organization_id,
            'workspace_id' => $site->workspace_id,
            'client_site_id' => $site->id,
            'source_type' => $sourceType,
            'name' => 'Sitemap: '.($site->name ?: $host),
            'base_url' => $sitemapUrl,
            'domain' => $host,
            'status' => MonitoredSource::STATUS_ACTIVE,
            'trust_level' => 5,
            'polling_frequency' => 'daily',
            'crawl_policy_json' => [
                'respect_robots' => true,
                'allow_discovery' => true,
                'allow_domains' => $allowedHosts,
            ],
            'fetch_config_json' => [
                'timeout_seconds' => (int) config('page_intelligence.discovery.timeout_seconds', 15),
            ],
            'discovery_config_json' => [
                'adapter' => 'xml_sitemap',
                'sitemap_url' => $sitemapUrl,
                'max_urls' => (int) config('website_content_inventory.sitemap.max_urls', 500),
                'page_type' => 'page',
                'fetch_priority_threshold' => (int) config('website_content_inventory.sitemap.fetch_priority_threshold', 80),
            ],
            'metadata_json' => array_filter([
                'website_content_inventory' => [
                    'managed_by' => 'sitemap_source_setup',
                    'candidate_paths' => (array) config('website_content_inventory.sitemap.candidate_paths', []),
                    'ensured_at' => Carbon::now()->toISOString(),
                ],
            ]),
        ];

        if ($source instanceof MonitoredSource) {
            $source->forceFill($payload);
            $source->isDirty() ? $result->sourcesUpdated++ : $result->sourcesUnchanged++;
            $source->save();
        } else {
            $source = MonitoredSource::query()->create($payload);
            $result->sourcesCreated++;
        }

        if ($dispatchDiscovery) {
            DiscoverMonitoredSourceUrlsJob::dispatch((string) $source->id);
            $result->discoveryJobsQueued++;
        }

        return $result;
    }

    private function sitePermitted(ClientSite $site): bool
    {
        if ((bool) config('website_content_inventory.sitemap.allow_unverified_domains', false)) {
            return true;
        }

        if ($site->analyticsSite?->isVerified()) {
            return true;
        }

        return (bool) data_get($site->connector_meta, 'website_content_inventory.sitemap_permitted', false);
    }

    private function sitemapUrl(ClientSite $site): ?string
    {
        $configured = trim((string) (
            data_get($site->connector_meta, 'sitemap_url')
            ?: data_get($site->automation_settings, 'sitemap_url')
            ?: data_get($site->connector_meta, 'website_content_inventory.sitemap_url')
        ));

        if ($configured !== '') {
            return $configured;
        }

        $base = trim((string) ($site->base_url ?: $site->site_url));
        if ($base === '') {
            return null;
        }

        $path = (string) (collect((array) config('website_content_inventory.sitemap.candidate_paths', ['/sitemap.xml']))
            ->filter(fn (mixed $candidate): bool => trim((string) $candidate) !== '')
            ->first() ?: '/sitemap.xml');

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    /**
     * @return array<int,string>
     */
    private function allowedHosts(ClientSite $site): array
    {
        $hosts = [];
        foreach (array_merge((array) $site->allowed_domains, [$site->base_url, $site->site_url]) as $value) {
            $host = $this->hostFromDomainOrUrl((string) $value);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private function hostFromDomainOrUrl(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '://')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }

        return trim($value, '*. ');
    }

    /**
     * @param  array<int,string>  $allowedHosts
     */
    private function hostAllowed(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function crossDomainOverrideAllowed(ClientSite $site): bool
    {
        return (bool) config('website_content_inventory.sitemap.allow_cross_domain_overrides', false)
            && (bool) data_get($site->connector_meta, 'website_content_inventory.allow_cross_domain_sitemap', false);
    }
}
