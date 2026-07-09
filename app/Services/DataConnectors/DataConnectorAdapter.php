<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorSyncRun;

interface DataConnectorAdapter extends ConnectorDatasetDiscoveryAdapter
{
    public function syncDataset(ConnectorDataset $dataset, ConnectorSyncRun $run): ConnectorSyncRun;
}
