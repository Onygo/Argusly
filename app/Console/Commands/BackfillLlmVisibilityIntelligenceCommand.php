<?php

namespace App\Console\Commands;

use App\Jobs\LlmTracking\RescoreLlmTrackingQueryJob;
use App\Models\LlmTrackingQuery;
use Illuminate\Console\Command;

class BackfillLlmVisibilityIntelligenceCommand extends Command
{
    protected $signature = 'llm-tracking:backfill-intelligence {--site-id=} {--max-dispatch=500} {--queue=default}';

    protected $description = 'Backfill provider-aware LLM visibility scores, authority entity candidates, and learnings from stored runs.';

    public function handle(): int
    {
        $siteId = trim((string) $this->option('site-id'));
        $maxDispatch = max(1, (int) $this->option('max-dispatch'));
        $queue = (string) $this->option('queue');
        $dispatched = 0;

        LlmTrackingQuery::query()
            ->when($siteId !== '', fn ($query) => $query->where('client_site_id', $siteId))
            ->whereHas('runs', fn ($query) => $query->where('status', 'succeeded')->whereNotNull('answer_text'))
            ->orderBy('id')
            ->chunkById(200, function ($queries) use (&$dispatched, $maxDispatch, $queue): bool {
                foreach ($queries as $query) {
                    if ($dispatched >= $maxDispatch) {
                        return false;
                    }

                    RescoreLlmTrackingQueryJob::dispatch((int) $query->id)->onQueue($queue);
                    $dispatched++;
                }

                return true;
            });

        $this->info('Queued LLM visibility intelligence backfill jobs: ' . $dispatched);

        return self::SUCCESS;
    }
}
