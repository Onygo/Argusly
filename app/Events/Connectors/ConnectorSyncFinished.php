<?php

namespace App\Events\Connectors;

use App\Services\DataConnectors\ConnectorSyncContext;
use Illuminate\Foundation\Events\Dispatchable;

class ConnectorSyncFinished
{
    use Dispatchable;

    /**
     * @param array<string, mixed> $metrics
     */
    public function __construct(
        public readonly ConnectorSyncContext $context,
        public readonly array $metrics = [],
    ) {
    }
}
