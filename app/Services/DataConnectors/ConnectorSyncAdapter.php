<?php

namespace App\Services\DataConnectors;

interface ConnectorSyncAdapter
{
    public function fetch(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): ConnectorSyncPage;
}
