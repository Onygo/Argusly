<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;

interface ConnectorDatasetDiscoveryAdapter
{
    public function providerKey(): string;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function discoverDatasets(ConnectorAccount $account): array;
}
