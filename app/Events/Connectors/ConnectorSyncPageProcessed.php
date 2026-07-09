<?php

namespace App\Events\Connectors;

use App\Services\DataConnectors\ConnectorObservationWriteResult;
use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncPage;
use Illuminate\Foundation\Events\Dispatchable;

class ConnectorSyncPageProcessed
{
    use Dispatchable;

    public function __construct(
        public readonly ConnectorSyncContext $context,
        public readonly ConnectorSyncPage $page,
        public readonly ConnectorObservationWriteResult $writeResult,
    ) {
    }
}
