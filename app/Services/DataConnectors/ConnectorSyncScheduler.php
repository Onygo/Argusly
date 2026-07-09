<?php

namespace App\Services\DataConnectors;

use App\Jobs\Connectors\SyncConnectorDatasetJob;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use Illuminate\Support\Carbon;

class ConnectorSyncScheduler
{
    public function dispatchDue(int $limit = 100, ?string $queue = null): int
    {
        $dispatched = 0;

        $this->dueDatasets($limit)->each(function (ConnectorDataset $dataset) use (&$dispatched, $queue): void {
            $this->reserveNextSync($dataset);

            $job = new SyncConnectorDatasetJob((string) $dataset->id);

            if ($queue) {
                $job->onQueue($queue);
            }

            dispatch($job);
            $dispatched++;
        });

        return $dispatched;
    }

    public function scheduleNext(ConnectorDataset $dataset, ?Carbon $from = null): void
    {
        $from ??= now();
        $next = $this->nextSyncAt((string) $dataset->sync_frequency, $from);

        $dataset->forceFill(['next_sync_at' => $next])->save();
        $dataset->account?->forceFill(['next_sync_at' => $next])->save();
    }

    private function reserveNextSync(ConnectorDataset $dataset): void
    {
        $this->scheduleNext($dataset);
    }

    private function dueDatasets(int $limit)
    {
        return ConnectorDataset::query()
            ->with('account')
            ->where('status', ConnectorDataset::STATUS_ACTIVE)
            ->whereIn('sync_frequency', ['hourly', 'daily', 'weekly'])
            ->where(function ($query): void {
                $query->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now());
            })
            ->whereHas('account', function ($query): void {
                $query->where('status', ConnectorAccount::STATUS_CONNECTED);
            })
            ->orderByRaw('COALESCE(next_sync_at, last_sync_at, discovered_at, created_at)')
            ->limit(max(1, $limit))
            ->get();
    }

    private function nextSyncAt(string $frequency, Carbon $from): ?Carbon
    {
        return match ($frequency) {
            'hourly' => $from->copy()->addHour(),
            'daily' => $from->copy()->addDay(),
            'weekly' => $from->copy()->addWeek(),
            default => null,
        };
    }
}
