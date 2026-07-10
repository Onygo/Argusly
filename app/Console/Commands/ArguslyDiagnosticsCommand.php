<?php

namespace App\Console\Commands;

use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\ContentPageLink;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\PageSnapshot;
use App\Services\WebsiteContentInventory\MonitoredPageRefreshSelector;
use App\Services\WebsiteContentInventory\WebsitePageEligibilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ArguslyDiagnosticsCommand extends Command
{
    protected $signature = 'argusly:diagnostics
        {--workspace= : Optional workspace UUID for inventory diagnostics}
        {--site= : Optional client_site UUID for inventory diagnostics}';

    protected $description = 'Show effective Argusly server and connector configuration (safe fields only).';

    public function __construct(
        private readonly WebsitePageEligibilityService $eligibility,
        private readonly MonitoredPageRefreshSelector $refreshSelector,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $webhookSecret = trim((string) config('argusly.webhooks.secret', ''));
        $connectorApiKey = trim((string) config('argusly_connector.api.api_key', config('argusly_connector.api_key', '')));
        $imageDisk = (string) config('argusly.images.disk', 'content_images');
        $imageDirectory = ContentImage::storageDirectory();
        $imageStorageDirectory = storage_path('app/public/'.$imageDirectory);
        $imagePublicLink = public_path($imageDirectory);

        $rows = [
            ['webhooks.secret', $webhookSecret !== '' ? 'set' : 'missing'],
            ['webhooks.connector_public_url', (string) config('argusly.webhooks.connector_public_url', '')],
            ['webhooks.queue', (string) config('argusly.webhooks.queue', 'deliveries')],
            ['images.enabled', (bool) config('argusly.images.enabled', true) ? 'true' : 'false'],
            ['images.disk', $imageDisk],
            ['images.path', $imageDirectory],
            ['images.disk.root', (string) config("filesystems.disks.{$imageDisk}.root", '')],
            ['images.disk.url', (string) config("filesystems.disks.{$imageDisk}.url", '')],
            ['images.storage_dir', File::isDirectory($imageStorageDirectory) ? 'exists' : "missing; create {$imageStorageDirectory}"],
            ['images.public_link', $this->publicLinkStatus($imagePublicLink, $imageStorageDirectory)],
            ['connector.api.base_url', (string) config('argusly_connector.api.base_url', config('argusly_connector.base_url', ''))],
            ['connector.api.workspace_id', (string) config('argusly_connector.api.workspace_id', config('argusly_connector.workspace_id', ''))],
            ['connector.api.api_key', $connectorApiKey !== '' ? 'set' : 'missing'],
        ];

        $this->table(['setting', 'value'], array_merge($rows, $this->inventoryDiagnosticsRows()));

        return self::SUCCESS;
    }

    private function publicLinkStatus(string $link, string $expectedTarget): string
    {
        if (is_link($link)) {
            $target = (string) readlink($link);

            return $target === $expectedTarget
                ? 'linked'
                : "linked to {$target} (expected {$expectedTarget})";
        }

        if (File::exists($link)) {
            return 'exists but is not a symlink';
        }

        return 'missing; run php artisan storage:link --force';
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    private function inventoryDiagnosticsRows(): array
    {
        if (! Schema::hasTable('content_page_links')
            || ! Schema::hasTable('monitored_pages')
            || ! Schema::hasColumn('contents', 'inventory_source_type')) {
            return [
                ['inventory.status', 'schema missing'],
            ];
        }

        [$eligible, $ineligible, $excluded] = $this->eligibilityCounts();
        $workspaceId = trim((string) $this->option('workspace'));
        $siteId = trim((string) $this->option('site'));
        $lastObserved = $this->lastObservedDiscoveryStats($workspaceId, $siteId);

        return [
            ['inventory.scope.workspace', $workspaceId !== '' ? $workspaceId : 'all'],
            ['inventory.scope.site', $siteId !== '' ? $siteId : 'all'],
            ['inventory.verified_client_sites', (string) $this->verifiedClientSitesCount($workspaceId, $siteId)],
            ['inventory.analytics_sites_eligible', (string) $this->analyticsSitesCount($workspaceId, $siteId)],
            ['inventory.analytics.last_observed_discovery_at', (string) ($lastObserved['finished_at'] ?? 'never')],
            ['inventory.analytics.urls_considered_last_run', (string) ($lastObserved['considered_urls'] ?? 0)],
            ['inventory.analytics.urls_submitted_last_run', (string) ($lastObserved['submitted_urls'] ?? 0)],
            ['inventory.analytics.urls_excluded_last_run', (string) ($lastObserved['excluded_urls'] ?? 0)],
            ['inventory.sitemap_sources_configured', (string) $this->sitemapSources($workspaceId, $siteId)->count()],
            ['inventory.sitemap.last_discovery_at', (string) ($this->sitemapSources($workspaceId, $siteId)->max('last_discovered_at') ?: 'never')],
            ['inventory.sitemap.urls_discovered_last_run', (string) $this->sitemapUrlsDiscoveredLastRun($workspaceId, $siteId)],
            ['inventory.monitored_pages.awaiting_first_fetch', (string) $this->monitoredPages($workspaceId, $siteId)->whereNull('last_fetched_at')->count()],
            ['inventory.monitored_pages.awaiting_extraction', (string) $this->awaitingExtractionCount($workspaceId, $siteId)],
            ['inventory.monitored_pages.stale', (string) $this->refreshSelector->query(['workspace_id' => $workspaceId ?: null, 'client_site_id' => $siteId ?: null])->count()],
            ['inventory.monitored_pages.temporary_failures', (string) $this->temporaryFailuresCount($workspaceId, $siteId)],
            ['inventory.monitored_pages.persistent_failures', (string) $this->persistentFailuresCount($workspaceId, $siteId)],
            ['inventory.monitored_pages.redirects', (string) $this->redirectSnapshotCount($workspaceId, $siteId)],
            ['inventory.monitored_pages.changed', (string) $this->monitoredPages($workspaceId, $siteId)->whereNotNull('last_changed_at')->count()],
            ['inventory.linked_monitored_pages', (string) $this->contentPageLinks($workspaceId, $siteId)->distinct('monitored_page_id')->count('monitored_page_id')],
            ['inventory.promoted_assets', (string) $this->contents($workspaceId, $siteId)->whereNotNull('inventory_source_type')->count()],
            ['inventory.orphan_monitored_pages', (string) $this->monitoredPages($workspaceId, $siteId)->whereDoesntHave('contentPageLinks')->count()],
            ['inventory.orphan_content', (string) $this->contents($workspaceId, $siteId)->whereNotNull('inventory_source_type')->whereDoesntHave('pageLinks')->count()],
            ['inventory.linked_content_assets', (string) $this->linkedContentAssetsCount($workspaceId, $siteId)],
            ['inventory.unlinked_eligible_pages', (string) $eligible],
            ['inventory.linked_assets_with_changed_observed_page', (string) $this->linkedAssetsWithChangedObservedPage($workspaceId, $siteId)],
            ['inventory.eligibility.eligible', (string) $eligible],
            ['inventory.eligibility.ineligible', (string) $ineligible],
            ['inventory.eligibility.excluded', (string) $excluded],
            ['inventory.warning.auto_fetch_after_analytics_discovery', (bool) config('website_content_inventory.analytics_observed.automatic_fetch_after_discovery', true) ? 'ok' : 'disabled'],
            ['inventory.warning.auto_extraction_after_refresh', (bool) config('website_content_inventory.refresh.automatic_extraction_after_fetch', true) ? 'ok' : 'disabled'],
        ];
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function eligibilityCounts(): array
    {
        $eligible = 0;
        $ineligible = 0;
        $excluded = 0;

        MonitoredPage::query()
            ->with('latestSnapshot')
            ->when(trim((string) $this->option('workspace')) !== '', fn ($query) => $query->where('workspace_id', trim((string) $this->option('workspace'))))
            ->when(trim((string) $this->option('site')) !== '', fn ($query) => $query->where('client_site_id', trim((string) $this->option('site'))))
            ->whereDoesntHave('contentPageLinks')
            ->orderBy('id')
            ->chunkById(200, function ($pages) use (&$eligible, &$ineligible, &$excluded): void {
                foreach ($pages as $page) {
                    $result = $this->eligibility->evaluate($page);

                    if ($result->eligible) {
                        $eligible++;
                    } else {
                        $ineligible++;
                    }

                    if (in_array('excluded_path', $result->reasons, true) || in_array('review_excluded', $result->reasons, true)) {
                        $excluded++;
                    }
                }
            }, 'id');

        return [$eligible, $ineligible, $excluded];
    }

    private function monitoredPages(string $workspaceId, string $siteId)
    {
        return MonitoredPage::query()
            ->when($workspaceId !== '', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($siteId !== '', fn ($query) => $query->where('client_site_id', $siteId));
    }

    private function contents(string $workspaceId, string $siteId)
    {
        return Content::query()
            ->when($workspaceId !== '', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($siteId !== '', fn ($query) => $query->where('client_site_id', $siteId));
    }

    private function contentPageLinks(string $workspaceId, string $siteId)
    {
        return ContentPageLink::query()
            ->when($workspaceId !== '', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($siteId !== '', fn ($query) => $query->where('client_site_id', $siteId));
    }

    private function sitemapSources(string $workspaceId, string $siteId)
    {
        return MonitoredSource::query()
            ->where('source_type', (string) config('website_content_inventory.sitemap.source_type', 'xml_sitemap'))
            ->when($workspaceId !== '', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($siteId !== '', fn ($query) => $query->where('client_site_id', $siteId));
    }

    private function verifiedClientSitesCount(string $workspaceId, string $siteId): int
    {
        return ClientSite::query()
            ->whereHas('analyticsSite', fn ($query) => $query->whereNotNull('verified_at')->where('is_enabled', true))
            ->when($workspaceId !== '', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($siteId !== '', fn ($query) => $query->whereKey($siteId))
            ->count();
    }

    private function analyticsSitesCount(string $workspaceId, string $siteId): int
    {
        return AnalyticsSite::query()
            ->whereNotNull('verified_at')
            ->where('is_enabled', true)
            ->whereNotNull('client_site_id')
            ->when($siteId !== '', fn ($query) => $query->where('client_site_id', $siteId))
            ->when($workspaceId !== '', fn ($query) => $query->whereHas('clientSite', fn ($site) => $site->where('workspace_id', $workspaceId)))
            ->count();
    }

    /**
     * @return array<string,mixed>
     */
    private function lastObservedDiscoveryStats(string $workspaceId, string $siteId): array
    {
        return AnalyticsSite::query()
            ->whereNotNull('verified_at')
            ->when($siteId !== '', fn ($query) => $query->where('client_site_id', $siteId))
            ->when($workspaceId !== '', fn ($query) => $query->whereHas('clientSite', fn ($site) => $site->where('workspace_id', $workspaceId)))
            ->get(['flags'])
            ->map(fn (AnalyticsSite $site): array => (array) data_get($site->flags, 'website_content_inventory.last_observed_page_discovery', []))
            ->filter()
            ->sortByDesc(fn (array $run): string => (string) ($run['finished_at'] ?? ''))
            ->first() ?? [];
    }

    private function sitemapUrlsDiscoveredLastRun(string $workspaceId, string $siteId): int
    {
        return $this->sitemapSources($workspaceId, $siteId)
            ->get(['metadata_json'])
            ->sum(fn (MonitoredSource $source): int => (int) data_get($source->metadata_json, 'last_discovery_run.discovered', 0));
    }

    private function awaitingExtractionCount(string $workspaceId, string $siteId): int
    {
        return PageSnapshot::query()
            ->whereNull('error_code')
            ->whereNotNull('raw_html_hash')
            ->whereDoesntHave('contentExtraction')
            ->when($workspaceId !== '', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($siteId !== '', fn ($query) => $query->where('client_site_id', $siteId))
            ->count();
    }

    private function temporaryFailuresCount(string $workspaceId, string $siteId): int
    {
        return $this->monitoredPages($workspaceId, $siteId)
            ->where(function ($query): void {
                $query->where('metadata_json', 'like', '%"availability_status":"temporary_failure"%')
                    ->orWhere('metadata_json', 'like', '%"availability_status": "temporary_failure"%')
                    ->orWhere('crawl_status', MonitoredPage::CRAWL_STATUS_FAILED);
            })
            ->count();
    }

    private function persistentFailuresCount(string $workspaceId, string $siteId): int
    {
        return $this->monitoredPages($workspaceId, $siteId)
            ->where(function ($query): void {
                $query->where('metadata_json', 'like', '%"availability_status":"persistent_not_found"%')
                    ->orWhere('metadata_json', 'like', '%"availability_status": "persistent_not_found"%');
            })
            ->count();
    }

    private function redirectSnapshotCount(string $workspaceId, string $siteId): int
    {
        return PageSnapshot::query()
            ->whereNotNull('redirect_chain_json')
            ->where('redirect_chain_json', 'not like', '[]')
            ->when($workspaceId !== '', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($siteId !== '', fn ($query) => $query->where('client_site_id', $siteId))
            ->count();
    }

    private function linkedContentAssetsCount(string $workspaceId, string $siteId): int
    {
        return ContentPageLink::query()
            ->when($workspaceId !== '', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($siteId !== '', fn ($query) => $query->where('client_site_id', $siteId))
            ->distinct('content_id')
            ->count('content_id');
    }

    private function linkedAssetsWithChangedObservedPage(string $workspaceId, string $siteId): int
    {
        return ContentPageLink::query()
            ->join('monitored_pages', 'monitored_pages.id', '=', 'content_page_links.monitored_page_id')
            ->leftJoin('contents', 'contents.id', '=', 'content_page_links.content_id')
            ->whereNotNull('monitored_pages.last_changed_at')
            ->where(function ($query): void {
                $query->whereNull('contents.external_changed_at')
                    ->orWhereColumn('monitored_pages.last_changed_at', '>', 'contents.external_changed_at');
            })
            ->when($workspaceId !== '', fn ($query) => $query->where('content_page_links.workspace_id', $workspaceId))
            ->when($siteId !== '', fn ($query) => $query->where('content_page_links.client_site_id', $siteId))
            ->distinct('content_page_links.content_id')
            ->count('content_page_links.content_id');
    }
}
