<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;

class ConnectorRateLimitService
{
    public function canAttempt(ConnectorAccount $account, ?ConnectorDataset $dataset = null): bool
    {
        unset($account, $dataset);

        return true;
    }

    /**
     * Placeholder for provider-specific rate-limit telemetry in a later phase.
     *
     * @return array<string, mixed>
     */
    public function snapshot(ConnectorAccount $account, ?ConnectorDataset $dataset = null): array
    {
        unset($account, $dataset);

        return [];
    }
}
