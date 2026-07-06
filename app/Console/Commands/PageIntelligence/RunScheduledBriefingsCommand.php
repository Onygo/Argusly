<?php

namespace App\Console\Commands\PageIntelligence;

use App\Jobs\PageIntelligence\GenerateScheduledPageIntelligenceBriefingJob;
use App\Models\ScheduledPageIntelligenceBriefing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RunScheduledBriefingsCommand extends Command
{
    protected $signature = 'page-intelligence:run-scheduled-briefings {--limit=50 : Maximum due schedules to dispatch}';

    protected $description = 'Dispatch due recurring Page Intelligence briefing snapshot jobs.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $lock = Cache::lock('page-intelligence:run-scheduled-briefings', 300);

        if (! $lock->get()) {
            $this->warn('Scheduled briefing dispatch is already running.');

            return self::SUCCESS;
        }

        try {
            $now = now();
            $schedules = ScheduledPageIntelligenceBriefing::query()
                ->where('is_active', true)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('next_run_at')
                        ->orWhere('next_run_at', '<=', $now);
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('scheduler_claim_expires_at')
                        ->orWhere('scheduler_claim_expires_at', '<=', $now);
                })
                ->orderByRaw('COALESCE(next_run_at, created_at)')
                ->limit($limit)
                ->get();

            $dispatched = 0;

            foreach ($schedules as $schedule) {
                if (! $schedule->next_run_at) {
                    $schedule->forceFill(['next_run_at' => $schedule->calculateNextRunAt($now->copy()->subSecond())])->save();
                }

                if ($schedule->next_run_at?->isFuture() || ! $this->localScheduleBoundaryReached($schedule, $now)) {
                    continue;
                }

                $claimToken = $this->claimSchedule($schedule, $now);
                if ($claimToken === null) {
                    continue;
                }

                GenerateScheduledPageIntelligenceBriefingJob::dispatch((string) $schedule->id, $claimToken);
                $dispatched++;
            }
        } finally {
            $lock->release();
        }

        $this->info(sprintf('Dispatched %d scheduled Page Intelligence briefing job(s).', $dispatched));

        return self::SUCCESS;
    }

    private function localScheduleBoundaryReached(ScheduledPageIntelligenceBriefing $schedule, \Illuminate\Support\Carbon $now): bool
    {
        $timezone = in_array((string) $schedule->timezone, timezone_identifiers_list(), true)
            ? (string) $schedule->timezone
            : 'UTC';
        $localNow = $now->copy()->timezone($timezone);
        $localRun = $schedule->next_run_at?->copy()->timezone($timezone);

        return $localRun !== null && $localRun->lessThanOrEqualTo($localNow);
    }

    private function claimSchedule(ScheduledPageIntelligenceBriefing $schedule, \Illuminate\Support\Carbon $now): ?string
    {
        $token = (string) Str::uuid();
        $claimed = ScheduledPageIntelligenceBriefing::query()
            ->whereKey($schedule->id)
            ->where('is_active', true)
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('scheduler_claim_expires_at')
                    ->orWhere('scheduler_claim_expires_at', '<=', $now);
            })
            ->update([
                'scheduler_claimed_at' => $now,
                'scheduler_claim_expires_at' => $now->copy()->addMinutes(10),
                'scheduler_claim_token' => $token,
                'updated_at' => $now,
            ]);

        return $claimed === 1 ? $token : null;
    }
}
