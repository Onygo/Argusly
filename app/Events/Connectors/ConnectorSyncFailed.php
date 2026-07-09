<?php

namespace App\Events\Connectors;

use App\Services\DataConnectors\ConnectorSyncContext;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

class ConnectorSyncFailed
{
    use Dispatchable;

    public function __construct(
        public readonly ConnectorSyncContext $context,
        public readonly Throwable $exception,
        public readonly bool $recoverable,
    ) {
    }
}
