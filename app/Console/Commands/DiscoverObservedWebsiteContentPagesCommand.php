<?php

namespace App\Console\Commands;

use App\Jobs\WebsiteContentInventory\DiscoverObservedAnalyticsPagesJob;
use App\Models\AnalyticsSite;
use App\Services\WebsiteContentInventory\ObservedAnalyticsPageDiscoveryService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class DiscoverObservedWebsiteContentPagesCommand extends Command
{
    protected $signature = 'website-content:discover-observed-pages
        {--workspace= : Optional workspace UUID}
        {--site= : Optional client_site UUID}
        {--analytics-site= : Optional analytics_site UUID}
        {--dry-run : Compute eligible observed pages without writing}
        {--chunk= : Analytics event chunk size}
        {--limit= : Maximum URLs to consider per analytics site}
        {--resume-after= : Resume analytics event processing after this integer event ID}
        {--sync : Run immediately instead of dispatching queued jobs}';

    protected $description = 'Discover public website pages observed by existing analytics tracking and submit them to Page Intelligence.';

    public function __construct(
        private readonly ObservedAnalyticsPageDiscoveryService $discovery,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sync = (bool) $this->option('sync') || $dryRun;
        $options = [
            'dry_run' => $dryRun,
            'chunk' => (int) $this->option('chunk'),
            'limit' => (int) $this->option('limit'),
            'resume_after' => trim((string) $this->option('resume-after')) !== '' ? (int) $this->option('resume-after') : null,
        ];

        $sites = $this->analyticsSites()->get();

        if ($sites->isEmpty()) {
            $this->warn('No verified analytics sites matched the requested scope.');

            return self::SUCCESS;
        }

        $this->line('Website content observed-page discovery');
        $this->line('Mode: '.($dryRun ? 'dry-run' : ($sync ? 'sync' : 'queued')));

        if (! $sync) {
            foreach ($sites as $site) {
                DiscoverObservedAnalyticsPagesJob::dispatch((string) $site->id, $options);
            }

            $this->info('Queued observed-page discovery jobs: '.$sites->count());

            return self::SUCCESS;
        }

        $rows = [];
        $totals = [
            'processed_events' => 0,
            'considered_urls' => 0,
            'submitted_urls' => 0,
            'created_pages' => 0,
            'updated_pages' => 0,
            'excluded_urls' => 0,
            'skipped_urls' => 0,
            'queued_fetches' => 0,
            'failed_urls' => 0,
        ];

        foreach ($sites as $site) {
            $result = $this->discovery->discoverForAnalyticsSite($site, $options);
            $array = $result->toArray();

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (int) ($array[$key] ?? 0);
            }

            $rows[] = [
                'analytics_site' => (string) $site->id,
                'site' => (string) $site->client_site_id,
                'processed' => (string) $result->processedEvents,
                'considered' => (string) $result->consideredUrls,
                'submitted' => (string) $result->submittedUrls,
                'excluded' => (string) $result->excludedUrls,
                'skipped' => (string) $result->skippedUrls,
                'queued_fetches' => (string) $result->queuedFetches,
                'last_event_id' => (string) ($result->lastEventId ?? ''),
            ];
        }

        $this->table([
            'analytics_site',
            'site',
            'processed',
            'considered',
            'submitted',
            'excluded',
            'skipped',
            'queued_fetches',
            'last_event_id',
        ], $rows);

        $this->table(['stat', 'value'], collect($totals)->map(fn (int $value, string $key): array => [$key, (string) $value])->values()->all());

        return self::SUCCESS;
    }

    private function analyticsSites(): Builder
    {
        $query = AnalyticsSite::query()
            ->with('clientSite.workspace')
            ->where('is_enabled', true)
            ->whereNotNull('verified_at')
            ->whereNotNull('client_site_id')
            ->whereHas('clientSite');

        $analyticsSite = trim((string) $this->option('analytics-site'));
        if ($analyticsSite !== '') {
            $query->whereKey($analyticsSite);
        }

        $site = trim((string) $this->option('site'));
        if ($site !== '') {
            $query->where('client_site_id', $site);
        }

        $workspace = trim((string) $this->option('workspace'));
        if ($workspace !== '') {
            $query->whereHas('clientSite', fn (Builder $siteQuery): Builder => $siteQuery->where('workspace_id', $workspace));
        }

        return $query->orderBy('id');
    }
}
