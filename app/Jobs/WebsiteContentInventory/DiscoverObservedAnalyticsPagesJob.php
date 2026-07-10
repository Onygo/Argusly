<?php

namespace App\Jobs\WebsiteContentInventory;

use App\Models\AnalyticsSite;
use App\Services\WebsiteContentInventory\ObservedAnalyticsPageDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DiscoverObservedAnalyticsPagesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public int $uniqueFor = 3600;

    /**
     * @param  array{dry_run?:bool,chunk?:int,limit?:int,resume_after?:int|null}  $options
     */
    public function __construct(
        public readonly string $analyticsSiteId,
        public readonly array $options = [],
    ) {
        $this->onQueue((string) config('website_content_inventory.analytics_observed.queue', 'page_intelligence_discover'));
    }

    public function handle(ObservedAnalyticsPageDiscoveryService $discovery): void
    {
        $site = AnalyticsSite::query()->find($this->analyticsSiteId);
        if (! $site instanceof AnalyticsSite) {
            return;
        }

        $result = $discovery->discoverForAnalyticsSite($site, $this->options);

        if ($result->failedUrls > 0) {
            Log::warning('website_content_inventory.analytics_observed.partial_failure', [
                'analytics_site_id' => $this->analyticsSiteId,
                'result' => $result->toArray(),
            ]);
        }
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))->releaseAfter(300)->expireAfter(1800),
        ];
    }

    /**
     * @return array<int,int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return 'website-content-inventory:analytics-observed:'.$this->analyticsSiteId;
    }

    public function failed(Throwable $exception): void
    {
        Log::error('website_content_inventory.analytics_observed.failed', [
            'analytics_site_id' => $this->analyticsSiteId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
