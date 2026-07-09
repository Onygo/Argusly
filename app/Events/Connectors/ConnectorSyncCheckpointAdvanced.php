<?php

namespace App\Events\Connectors;

use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncCursor;
use Illuminate\Foundation\Events\Dispatchable;

class ConnectorSyncCheckpointAdvanced
{
    use Dispatchable;

    public function __construct(
        public readonly ConnectorSyncContext $context,
        public readonly ConnectorSyncCursor $cursor,
    ) {
    }
}
