<?php

namespace App\Services\WebsiteContentInventory;

class SitemapSourceSetupResult
{
    /**
     * @param  array<int,string>  $messages
     */
    public function __construct(
        public int $sitesProcessed = 0,
        public int $sourcesCreated = 0,
        public int $sourcesUpdated = 0,
        public int $sourcesUnchanged = 0,
        public int $sourcesRejected = 0,
        public int $discoveryJobsQueued = 0,
        public array $messages = [],
        public bool $dryRun = false,
    ) {}

    public function message(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'sites_processed' => $this->sitesProcessed,
            'sources_created' => $this->sourcesCreated,
            'sources_updated' => $this->sourcesUpdated,
            'sources_unchanged' => $this->sourcesUnchanged,
            'sources_rejected' => $this->sourcesRejected,
            'discovery_jobs_queued' => $this->discoveryJobsQueued,
            'messages' => $this->messages,
            'dry_run' => $this->dryRun,
        ];
    }
}
