<?php

namespace App\Events\Connectors;

use App\Services\DataConnectors\ConnectorSyncContext;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConnectorRawRecordsWritten
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ConnectorSyncContext $context,
        public readonly int $recordCount,
    ) {}
}
