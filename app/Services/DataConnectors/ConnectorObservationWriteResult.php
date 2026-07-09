<?php

namespace App\Services\DataConnectors;

use Illuminate\Support\Collection;

class ConnectorObservationWriteResult
{
    /**
     * @param Collection<int, \App\Models\MarketingObservation> $observations
     */
    public function __construct(
        public readonly int $written,
        public readonly Collection $observations,
    ) {
    }
}
