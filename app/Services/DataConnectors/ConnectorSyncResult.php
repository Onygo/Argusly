<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorSyncRun;

class ConnectorSyncResult
{
    /**
     * @param array<string, mixed> $metrics
     */
    public function __construct(
        public readonly ConnectorSyncRun $run,
        public readonly array $metrics,
        public readonly ConnectorSyncCursor $cursor,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->run->status === ConnectorSyncRun::STATUS_SUCCEEDED;
    }
}
