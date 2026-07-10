<?php

namespace App\Jobs\Connectors;

use App\Services\DataConnectors\Normalization\ConnectorNormalizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TransformConnectorRawRecordsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 120;

    public function __construct(public readonly string $normalizationRunId)
    {
    }

    public function handle(ConnectorNormalizationService $normalization): void
    {
        $normalization->normalizeRunId($this->normalizationRunId);
    }
}
