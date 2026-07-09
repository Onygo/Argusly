<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorSyncRun;

class ConnectorSyncContext
{
    public function __construct(
        public readonly ConnectorSyncPlan $plan,
        public readonly ConnectorSyncRun $run,
    ) {
    }

    public function providerKey(): string
    {
        return $this->plan->provider;
    }
}
