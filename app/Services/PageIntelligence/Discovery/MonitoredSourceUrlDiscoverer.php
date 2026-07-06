<?php

namespace App\Services\PageIntelligence\Discovery;

use App\Jobs\PageIntelligence\FetchMonitoredPageJob;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Workspace;
use App\Services\PageIntelligence\SubmitMonitoredPageAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class MonitoredSourceUrlDiscoverer
{
    public function __construct(
        private readonly SubmitMonitoredPageAction $submitPage,
    ) {
    }

    public function discover(MonitoredSource $source): MonitoredSourceDiscoveryResult
    {
        $startedAt = Carbon::now();

        if (! in_array((string) $source->status, [MonitoredSource::STATUS_ACTIVE, MonitoredSource::STATUS_NEW], true)) {
            $this->recordSkipped($source, $startedAt, 'inactive_source');

            return new MonitoredSourceDiscoveryResult($source->refresh(), 0, 0, 0, 0, true, true, 'inactive_source');
        }

        if (! $this->discoveryAllowed($source)) {
            $this->recordSkipped($source, $startedAt, 'discovery_disabled_by_policy');

            return new MonitoredSourceDiscoveryResult($source->refresh(), 0, 0, 0, 0, true, true, 'discovery_disabled_by_policy');
        }

        try {
            $workspace = Workspace::query()->find($source->workspace_id);
            if (! $workspace instanceof Workspace) {
                throw new RuntimeException('Monitored source workspace not found.');
            }

            $adapter = $this->adapterFor($source);
            $maxUrls = $this->maxUrls($source);
            $fetchThreshold = $this->fetchPriorityThreshold($source);
            $seen = [];
            $created = 0;
            $updated = 0;
            $queued = 0;
            $failed = 0;

            foreach ($adapter->discover($source) as $discovered) {
                if (count($seen) >= $maxUrls) {
                    break;
                }

                if (! $discovered instanceof DiscoveredUrl || trim($discovered->url) === '') {
                    continue;
                }

                $dedupeKey = hash('sha256', trim($discovered->canonicalUrl ?: $discovered->url));
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                try {
                    $result = $this->submitPage->execute(
                        workspace: $workspace,
                        url: $discovered->url,
                        sourceType: (string) $source->source_type,
                        pageType: $discovered->pageType,
                        canonicalUrl: $discovered->canonicalUrl,
                        source: $source,
                        extraMetadata: [
                            'discovery' => array_filter([
                                'source_id' => $source->id,
                                'adapter' => $this->adapterKey($source),
                                'priority' => $discovered->priority,
                                'title' => $discovered->title,
                                'published_at' => $discovered->publishedAt?->format(DATE_ATOM),
                                'metadata' => $discovered->metadata,
                            ], fn (mixed $value): bool => $value !== null),
                        ],
                    );
                    $result->created ? $created++ : $updated++;

                    $this->applyDiscoveryHints($result->page, $discovered);

                    if ($discovered->priority >= $fetchThreshold && ($result->created || $this->shouldFetchUpdatedPage($result->page))) {
                        FetchMonitoredPageJob::dispatch((string) $result->page->id, $result->page->first_seen_url);
                        $queued++;
                    }
                } catch (Throwable $exception) {
                    $failed++;
                }

                $this->respectRateLimit($source);
            }

            $this->recordSuccess($source, $startedAt, count($seen), $created, $updated, $queued, $failed);

            return new MonitoredSourceDiscoveryResult($source->refresh(), count($seen), $created, $updated, $queued, true, false, null, $failed);
        } catch (Throwable $exception) {
            $this->recordFailure($source, $startedAt, $exception);

            return new MonitoredSourceDiscoveryResult($source->refresh(), 0, 0, 0, 0, false, false, $exception->getMessage());
        }
    }

    public function adapterFor(MonitoredSource $source): DiscoveryAdapter
    {
        return match ($this->adapterKey($source)) {
            'rss', 'feed' => app(RssDiscoveryAdapter::class),
            'xml_sitemap', 'sitemap' => app(XmlSitemapDiscoveryAdapter::class),
            'known_source_crawl', 'known_source', 'competitor_crawl', 'press_room' => app(KnownSourceCrawlAdapter::class),
            'manual', 'manual_url', 'manual_urls' => app(ManualUrlDiscoveryAdapter::class),
            default => throw new InvalidArgumentException('Unsupported monitored source discovery adapter: '.$this->adapterKey($source)),
        };
    }

    private function adapterKey(MonitoredSource $source): string
    {
        $config = (array) ($source->discovery_config_json ?? []);

        return Str::snake(strtolower((string) ($config['adapter'] ?? $source->source_type)));
    }

    private function applyDiscoveryHints(MonitoredPage $page, DiscoveredUrl $discovered): void
    {
        $updates = [];

        if ($discovered->title && ! $page->title_current) {
            $updates['title_current'] = $discovered->title;
        }

        if ($discovered->publishedAt && ! $page->published_at_current) {
            $updates['published_at_current'] = $discovered->publishedAt;
        }

        if ($updates !== []) {
            $page->forceFill($updates)->save();
        }
    }

    private function shouldFetchUpdatedPage(MonitoredPage $page): bool
    {
        return $page->last_fetched_at === null;
    }

    private function maxUrls(MonitoredSource $source): int
    {
        $config = (array) ($source->discovery_config_json ?? []);

        return max(1, (int) ($config['max_urls'] ?? config('page_intelligence.discovery.max_urls', 100)));
    }

    private function discoveryAllowed(MonitoredSource $source): bool
    {
        $policy = (array) ($source->crawl_policy_json ?? []);
        $config = (array) ($source->discovery_config_json ?? []);

        return (bool) ($policy['allow_discovery'] ?? $config['enabled'] ?? true);
    }

    private function fetchPriorityThreshold(MonitoredSource $source): int
    {
        $config = (array) ($source->discovery_config_json ?? []);

        return max(0, min(100, (int) ($config['fetch_priority_threshold'] ?? config('page_intelligence.discovery.fetch_priority_threshold', 80))));
    }

    private function respectRateLimit(MonitoredSource $source): void
    {
        $policy = (array) ($source->crawl_policy_json ?? []);
        $delayMs = max(0, (int) ($policy['discovery_delay_ms'] ?? $policy['delay_ms'] ?? 0));

        if ($delayMs > 0 && ! app()->environment('testing')) {
            usleep($delayMs * 1000);
        }
    }

    private function recordSuccess(MonitoredSource $source, Carbon $startedAt, int $discovered, int $created, int $updated, int $queued, int $failed): void
    {
        $metadata = (array) ($source->metadata_json ?? []);
        $metadata['last_discovery_run'] = [
            'status' => 'completed',
            'started_at' => $startedAt->toISOString(),
            'finished_at' => Carbon::now()->toISOString(),
            'discovered' => $discovered,
            'created' => $created,
            'updated' => $updated,
            'fetch_jobs_queued' => $queued,
            'failed_urls' => $failed,
        ];

        $source->forceFill([
            'last_discovered_at' => Carbon::now(),
            'failure_count' => $failed > 0 ? (int) $source->failure_count + $failed : (int) $source->failure_count,
            'last_error' => $failed > 0 ? $failed.' discovered URL(s) failed to normalize or upsert.' : null,
            'metadata_json' => $metadata,
        ])->save();
    }

    private function recordSkipped(MonitoredSource $source, Carbon $startedAt, string $reason): void
    {
        $metadata = (array) ($source->metadata_json ?? []);
        $metadata['last_discovery_run'] = [
            'status' => 'skipped',
            'reason' => $reason,
            'started_at' => $startedAt->toISOString(),
            'finished_at' => Carbon::now()->toISOString(),
        ];

        $source->forceFill(['metadata_json' => $metadata])->save();
    }

    private function recordFailure(MonitoredSource $source, Carbon $startedAt, Throwable $exception): void
    {
        $metadata = (array) ($source->metadata_json ?? []);
        $metadata['last_discovery_run'] = [
            'status' => 'failed',
            'started_at' => $startedAt->toISOString(),
            'finished_at' => Carbon::now()->toISOString(),
            'error' => $exception->getMessage(),
        ];

        $source->forceFill([
            'failure_count' => (int) $source->failure_count + 1,
            'last_error' => $exception->getMessage(),
            'metadata_json' => $metadata,
        ])->save();
    }
}
