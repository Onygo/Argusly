<?php

namespace App\Listeners\Connectors;

use App\Events\Connectors\ConnectorSyncCompletedForTransformation;
use App\Services\DataConnectors\Normalization\ConnectorNormalizationService;

class QueueConnectorSyncNormalization
{
    public function __construct(private readonly ConnectorNormalizationService $normalization)
    {
    }

    public function handle(ConnectorSyncCompletedForTransformation $event): void
    {
        $this->normalization->enqueueForSyncContext($event->context, 'sync_completed_for_transformation', [
            'metrics' => $event->metrics,
        ]);
    }
}
