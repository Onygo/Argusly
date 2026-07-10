<?php

namespace App\Services\WebsiteContentInventory;

class ObservedAnalyticsPageDiscoveryResult
{
    /**
     * @param  array<string,int>  $skipReasons
     * @param  array<int,array<string,string>>  $failures
     */
    public function __construct(
        public int $processedEvents = 0,
        public int $consideredUrls = 0,
        public int $submittedUrls = 0,
        public int $createdPages = 0,
        public int $updatedPages = 0,
        public int $excludedUrls = 0,
        public int $skippedUrls = 0,
        public int $queuedFetches = 0,
        public int $failedUrls = 0,
        public ?int $lastEventId = null,
        public array $skipReasons = [],
        public array $failures = [],
        public bool $dryRun = false,
    ) {}

    public function skip(string $reason): void
    {
        $this->skippedUrls++;
        $this->skipReasons[$reason] = (int) ($this->skipReasons[$reason] ?? 0) + 1;
    }

    public function exclude(string $reason): void
    {
        $this->excludedUrls++;
        $this->skipReasons[$reason] = (int) ($this->skipReasons[$reason] ?? 0) + 1;
    }

    public function fail(string $url, string $message): void
    {
        $this->failedUrls++;
        $this->failures[] = [
            'url' => $url,
            'message' => $message,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'processed_events' => $this->processedEvents,
            'considered_urls' => $this->consideredUrls,
            'submitted_urls' => $this->submittedUrls,
            'created_pages' => $this->createdPages,
            'updated_pages' => $this->updatedPages,
            'excluded_urls' => $this->excludedUrls,
            'skipped_urls' => $this->skippedUrls,
            'queued_fetches' => $this->queuedFetches,
            'failed_urls' => $this->failedUrls,
            'last_event_id' => $this->lastEventId,
            'skip_reasons' => $this->skipReasons,
            'failures' => $this->failures,
            'dry_run' => $this->dryRun,
        ];
    }
}
