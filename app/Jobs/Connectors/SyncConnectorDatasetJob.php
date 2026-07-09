<?php

namespace App\Jobs\Connectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorSyncRun;
use App\Services\DataConnectors\ConnectorAuditLogger;
use App\Services\DataConnectors\ConnectorSyncEngine;
use App\Services\DataConnectors\ConnectorSyncPlan;
use App\Services\DataConnectors\ConnectorSyncScheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncConnectorDatasetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 300;

    public function __construct(
        public readonly string $connectorDatasetId,
        public readonly string $runType = ConnectorSyncRun::TYPE_SCHEDULED,
    ) {
    }

    public function handle(
        ConnectorSyncEngine $engine,
        ConnectorSyncScheduler $scheduler,
        ConnectorAuditLogger $audit,
    ): void {
        $dataset = ConnectorDataset::query()
            ->with(['account', 'workspace', 'clientSite'])
            ->find($this->connectorDatasetId);

        if (! $dataset instanceof ConnectorDataset) {
            return;
        }

        if ($this->runType !== ConnectorSyncRun::TYPE_MANUAL && ! $dataset->isSyncEligible()) {
            return;
        }

        if ($this->runType === ConnectorSyncRun::TYPE_MANUAL && $dataset->status !== ConnectorDataset::STATUS_ACTIVE) {
            return;
        }

        if ($dataset->account?->status !== ConnectorAccount::STATUS_CONNECTED) {
            return;
        }

        $result = $engine->sync(ConnectorSyncPlan::forDataset($dataset, $this->runType));

        if ($this->runType === ConnectorSyncRun::TYPE_SCHEDULED && $result->succeeded()) {
            $scheduler->scheduleNext($dataset->fresh());
        }

        $audit->record($dataset, 'connector.dataset_synced', null, [
            'workspace_id' => $dataset->workspace_id,
            'provider_key' => $dataset->provider_key,
            'sync_run_id' => $result->run->id,
            'status' => $result->run->status,
            'metrics' => $result->metrics,
        ]);
    }
}
