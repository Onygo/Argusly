<?php

namespace App\Console\Commands;

use App\Jobs\AgenticMarketing\DetectAgenticMarketingOpportunitiesJob;
use App\Services\AgenticMarketing\AgenticMarketingOpportunityDetectionService;
use Illuminate\Console\Command;

class DetectAgenticMarketingOpportunitiesCommand extends Command
{
    protected $signature = 'agentic-marketing:detect-opportunities
        {objective? : Optional objective UUID}
        {--queue : Dispatch detection to the queue instead of running inline}';

    protected $description = 'Detect deterministic Agentic Marketing opportunities from stored PublishLayer intelligence signals.';

    public function handle(AgenticMarketingOpportunityDetectionService $detection): int
    {
        $objectiveId = $this->argument('objective');
        $objectiveId = is_string($objectiveId) && trim($objectiveId) !== '' ? trim($objectiveId) : null;

        if ($this->option('queue')) {
            DetectAgenticMarketingOpportunitiesJob::dispatch($objectiveId);
            $this->info('Queued Agentic Marketing opportunity detection.');

            return self::SUCCESS;
        }

        $result = $detection->detect($objectiveId);

        $this->info(sprintf(
            'Detected opportunities for %d objective(s): %d created, %d reused.',
            (int) ($result['objectives'] ?? 0),
            (int) ($result['created'] ?? 0),
            (int) ($result['reused'] ?? 0),
        ));

        if ((int) ($result['failed'] ?? 0) > 0) {
            $this->warn(sprintf('%d objective(s) failed during detection.', (int) $result['failed']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
