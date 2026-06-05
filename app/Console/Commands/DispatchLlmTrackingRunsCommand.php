<?php

namespace App\Console\Commands;

use App\Jobs\LlmTracking\RunLlmTrackingQueryJob;
use App\Models\LlmTrackingQuery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class DispatchLlmTrackingRunsCommand extends Command
{
    protected $signature = 'llm-tracking:dispatch-daily {--max-dispatch=120} {--queue=default}';

    protected $description = 'Dispatch due LLM visibility tracking query runs for daily and weekly cadences.';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $startOfDay = $now->startOfDay();
        $startOfWeek = $now->startOfWeek();
        $currentHourBucket = (int) $now->format('G');
        $currentDayBucket = (int) $now->dayOfWeekIso - 1;
        $maxDispatch = max(1, (int) $this->option('max-dispatch'));
        $queue = (string) $this->option('queue');

        $dispatched = 0;

        LlmTrackingQuery::query()
            ->with('site.workspace')
            ->withMax('runs', 'run_at')
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(200, function ($queries) use (&$dispatched, $maxDispatch, $queue, $currentHourBucket, $currentDayBucket, $startOfDay, $startOfWeek, $now) {
                foreach ($queries as $query) {
                    if ($dispatched >= $maxDispatch) {
                        return false;
                    }

                    if (! $query->site?->workspace || ! $query->site->is_active || $query->site->status === 'disabled') {
                        continue;
                    }

                    $latestRunAtRaw = data_get($query, 'runs_max_run_at');
                    $latestRunAt = $latestRunAtRaw ? CarbonImmutable::parse((string) $latestRunAtRaw) : null;
                    $frequency = in_array((string) $query->frequency, ['daily', 'weekly'], true)
                        ? (string) $query->frequency
                        : 'daily';

                    $alreadySatisfied = match ($frequency) {
                        'weekly' => $latestRunAt && $latestRunAt->greaterThanOrEqualTo($startOfWeek),
                        default => $latestRunAt && $latestRunAt->greaterThanOrEqualTo($startOfDay),
                    };
                    if ($alreadySatisfied) {
                        continue;
                    }

                    if ($frequency === 'weekly') {
                        $bucket = abs(crc32((string) $query->id)) % 7;
                        $overdue = ! $latestRunAt || $latestRunAt->lessThanOrEqualTo($now->subDays(8));

                        if (! $overdue && $bucket !== $currentDayBucket) {
                            continue;
                        }
                    } else {
                        $bucket = abs(crc32((string) $query->id)) % 24;
                        $overdue = ! $latestRunAt || $latestRunAt->lessThanOrEqualTo($now->subHours(30));

                        if (! $overdue && $bucket !== $currentHourBucket) {
                            continue;
                        }
                    }

                    RunLlmTrackingQueryJob::dispatch($query->id, $now->toDateString())->onQueue($queue);
                    $dispatched++;
                }

                return true;
            });

        $this->info('Dispatched LLM tracking runs: ' . $dispatched);

        return self::SUCCESS;
    }
}
