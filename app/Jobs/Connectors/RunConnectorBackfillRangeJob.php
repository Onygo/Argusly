<?php

namespace App\Jobs\Connectors;

use App\Models\Connectors\ConnectorBackfillRange;
use App\Models\Connectors\ConnectorSyncRun;
use App\Services\DataConnectors\ConnectorAuditLogger;
use App\Services\DataConnectors\ConnectorSyncCursor;
use App\Services\DataConnectors\ConnectorSyncEngine;
use App\Services\DataConnectors\ConnectorSyncPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunConnectorBackfillRangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 300;

    public function __construct(public readonly string $connectorBackfillRangeId)
    {
    }

    public function handle(ConnectorSyncEngine $engine, ConnectorAuditLogger $audit): void
    {
        $range = ConnectorBackfillRange::query()
            ->with(['account', 'dataset.workspace', 'dataset.clientSite'])
            ->find($this->connectorBackfillRangeId);

        if (! $range instanceof ConnectorBackfillRange || ! in_array($range->status, [
            ConnectorBackfillRange::STATUS_PENDING,
            ConnectorBackfillRange::STATUS_FAILED,
        ], true)) {
            return;
        }

        $dataset = $range->dataset;
        $account = $range->account;

        if (! $dataset || ! $account) {
            return;
        }

        $range->forceFill([
            'status' => ConnectorBackfillRange::STATUS_RUNNING,
            'attempts' => ((int) $range->attempts) + 1,
            'last_error' => null,
        ])->save();

        $result = $engine->sync(new ConnectorSyncPlan(
            workspace: $dataset->workspace,
            clientSite: $dataset->clientSite,
            provider: $dataset->provider_key,
            account: $account,
            dataset: $dataset,
            incremental: false,
            backfill: true,
            dateRangeStart: $range->range_start,
            dateRangeEnd: $range->range_end,
            forceRefresh: true,
            checkpoint: new ConnectorSyncCursor,
            runType: ConnectorSyncRun::TYPE_BACKFILL,
        ));

        $range->forceFill([
            'status' => $result->succeeded()
                ? ConnectorBackfillRange::STATUS_SUCCEEDED
                : ConnectorBackfillRange::STATUS_FAILED,
            'connector_sync_run_id' => $result->run->id,
            'last_error' => $result->succeeded() ? null : $result->run->error_message,
        ])->save();

        $audit->record($dataset, 'connector.backfill_range_finished', null, [
            'workspace_id' => $dataset->workspace_id,
            'provider_key' => $dataset->provider_key,
            'backfill_range_id' => $range->id,
            'sync_run_id' => $result->run->id,
            'status' => $result->run->status,
        ]);
    }
}
