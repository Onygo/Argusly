<?php

namespace App\Console\Commands;

use App\Jobs\LlmTracking\BuildLlmTrackingAggregatesJob;
use App\Services\LlmTracking\LlmTrackingAggregateBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class BuildLlmTrackingAggregatesCommand extends Command
{
    protected $signature = 'llm-tracking:build-aggregates
        {--from-date= : Optional YYYY-MM-DD lower bound for run_at}
        {--queue=default : Queue name when async}
        {--sync : Run immediately in this process}';

    protected $description = 'Build day/week/month LLM visibility aggregates.';

    public function handle(LlmTrackingAggregateBuilder $builder): int
    {
        $fromDateOption = trim((string) $this->option('from-date'));
        $fromDate = $fromDateOption !== '' ? CarbonImmutable::parse($fromDateOption)->toDateString() : null;

        if ((bool) $this->option('sync')) {
            $count = $builder->build($fromDate ? CarbonImmutable::parse($fromDate) : null);
            $this->info('LLM tracking aggregates built: ' . $count);

            return self::SUCCESS;
        }

        BuildLlmTrackingAggregatesJob::dispatch($fromDate)->onQueue((string) $this->option('queue'));
        $this->info('LLM tracking aggregate build job queued.');

        return self::SUCCESS;
    }
}
