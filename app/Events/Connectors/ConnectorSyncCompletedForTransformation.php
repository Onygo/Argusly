<?php

namespace App\Events\Connectors;

use App\Services\DataConnectors\ConnectorSyncContext;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConnectorSyncCompletedForTransformation
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $metrics
     */
    public function __construct(
        public readonly ConnectorSyncContext $context,
        public readonly array $metrics,
    ) {}
}
