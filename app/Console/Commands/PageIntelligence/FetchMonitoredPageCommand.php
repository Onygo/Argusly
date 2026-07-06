<?php

namespace App\Console\Commands\PageIntelligence;

use App\Jobs\PageIntelligence\FetchMonitoredPageJob;
use App\Models\MonitoredPage;
use App\Services\PageIntelligence\PageFetcher;
use Illuminate\Console\Command;

class FetchMonitoredPageCommand extends Command
{
    protected $signature = 'page-intelligence:fetch
        {monitoredPageId : Monitored page UUID}
        {--url= : Optional requested URL override}
        {--sync : Fetch immediately instead of dispatching a queued job}';

    protected $description = 'Fetch a monitored page URL and store a versioned PageSnapshot.';

    public function handle(PageFetcher $fetcher): int
    {
        $page = MonitoredPage::query()->find((string) $this->argument('monitoredPageId'));
        if (! $page instanceof MonitoredPage) {
            $this->error('Monitored page not found.');

            return self::FAILURE;
        }

        $requestedUrl = trim((string) $this->option('url')) ?: null;

        if (! (bool) $this->option('sync')) {
            FetchMonitoredPageJob::dispatch((string) $page->id, $requestedUrl);

            $this->info('Monitored page fetch queued.');
            $this->line('ID: '.$page->id);
            $this->line('Workspace: '.$page->workspace_id);
            $this->line('Requested URL: '.($requestedUrl ?: $page->final_url ?: $page->canonical_url ?: $page->first_seen_url));

            return self::SUCCESS;
        }

        $result = $fetcher->fetch($page, $requestedUrl);

        $this->info(sprintf('Monitored page fetch %s.', $result->state()));
        $this->line('ID: '.$result->page->id);
        $this->line('Snapshot: '.$result->snapshot->id);
        $this->line('Snapshot number: '.$result->snapshot->snapshot_number);
        $this->line('HTTP status: '.($result->snapshot->http_status ?? 'none'));
        $this->line('Final URL: '.($result->snapshot->final_url ?: 'none'));
        $this->line('Content changed: '.($result->snapshot->content_changed ? 'yes' : 'no'));
        $this->line('Crawl status: '.$result->page->crawl_status);
        if ($result->snapshot->error_code) {
            $this->line('Error code: '.$result->snapshot->error_code);
            $this->line('Error message: '.$result->snapshot->error_message);
        }

        return $result->successful ? self::SUCCESS : self::FAILURE;
    }
}
