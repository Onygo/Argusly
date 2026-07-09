<?php

namespace App\Events\Connectors;

use App\Services\DataConnectors\ConnectorSyncContext;
use Illuminate\Foundation\Events\Dispatchable;

class ConnectorSyncCancelled
{
    use Dispatchable;

    public function __construct(public readonly ConnectorSyncContext $context)
    {
    }
}
