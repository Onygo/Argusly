<?php

namespace App\Listeners\Connectors;

use App\Events\Connectors\ConnectorRawRecordsWritten;
use App\Services\DataConnectors\Normalization\ConnectorNormalizationService;

class QueueConnectorRawRecordNormalization
{
    public function __construct(private readonly ConnectorNormalizationService $normalization)
    {
    }

    public function handle(ConnectorRawRecordsWritten $event): void
    {
        $this->normalization->enqueueForSyncContext($event->context, 'raw_records_written', [
            'record_count' => $event->recordCount,
        ]);
    }
}
