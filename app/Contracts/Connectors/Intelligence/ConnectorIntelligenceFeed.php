<?php

namespace App\Contracts\Connectors\Intelligence;

use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorSyncRun;

interface ConnectorIntelligenceFeed
{
    public function key(): string;

    public function supports(ConnectorDataset $dataset): bool;

    public function consume(ConnectorSyncRun $run): void;
}
