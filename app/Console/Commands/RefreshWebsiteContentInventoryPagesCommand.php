<?php

namespace App\Console\Commands;

use App\Jobs\PageIntelligence\FetchMonitoredPageJob;
use App\Models\MonitoredPage;
use App\Services\PageIntelligence\PageFetcher;
use App\Services\PageIntelligence\PageIntelligencePipelineOrchestrator;
use App\Services\WebsiteContentInventory\MonitoredPageRefreshSelector;
use Illuminate\Console\Command;

class RefreshWebsiteContentInventoryPagesCommand extends Command
{
    protected $signature = 'website-content:refresh-pages
        {--workspace= : Optional workspace UUID}
        {--site= : Optional client_site UUID}
        {--limit= : Maximum pages to refresh}
        {--dry-run : Show selected pages without queueing}
        {--sync : Fetch selected pages immediately instead of dispatching jobs}';

    protected $description = 'Queue stale or never-fetched website inventory pages for the existing Page Intelligence fetch pipeline.';

    public function __construct(private readonly MonitoredPageRefreshSelector $selector)
    {
        parent::__construct();
    }

    public function handle(PageFetcher $fetcher, PageIntelligencePipelineOrchestrator $orchestrator): int
    {
        $limit = (int) ($this->option('limit') ?: config('website_content_inventory.refresh.limit', 100));
        $pages = $this->selector->query([
            'workspace_id' => trim((string) $this->option('workspace')) ?: null,
            'client_site_id' => trim((string) $this->option('site')) ?: null,
            'limit' => $limit,
        ])->get();

        $queued = 0;
        $fetched = 0;

        foreach ($pages as $page) {
            if ((bool) $this->option('dry-run')) {
                continue;
            }

            if ((bool) $this->option('sync')) {
                $result = $fetcher->fetch($page, $page->first_seen_url);
                if ($result->successful && (bool) config('website_content_inventory.refresh.automatic_extraction_after_fetch', true)) {
                    $orchestrator->dispatchSnapshotPipeline($result->snapshot);
                }
                $fetched++;

                continue;
            }

            FetchMonitoredPageJob::dispatch(
                (string) $page->id,
                $page->first_seen_url,
                (bool) config('website_content_inventory.refresh.automatic_extraction_after_fetch', true),
            );
            $queued++;
        }

        $this->table(['stat', 'value'], [
            ['selected_pages', (string) $pages->count()],
            ['queued_fetches', (string) $queued],
            ['sync_fetches', (string) $fetched],
            ['dry_run', (bool) $this->option('dry-run') ? 'true' : 'false'],
            ['last_page_id', (string) ($pages->last()?->id ?? '')],
        ]);

        if ((bool) $this->option('dry-run') && $pages->isNotEmpty()) {
            $this->table(['page', 'url', 'crawl_status', 'last_fetched'], $pages->map(fn (MonitoredPage $page): array => [
                (string) $page->id,
                (string) ($page->canonical_url ?: $page->first_seen_url),
                (string) $page->crawl_status,
                (string) ($page->last_fetched_at?->toDateTimeString() ?? ''),
            ])->all());
        }

        return self::SUCCESS;
    }
}
