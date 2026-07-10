<?php

namespace App\Contracts\Connectors\Normalization;

use App\Data\Connectors\NormalizedRecord;
use App\Models\Connectors\ConnectorRawRecord;

interface NormalizedRecordMapper
{
    public function provider(): string;

    /**
     * @return array<int, NormalizedRecord>
     */
    public function map(ConnectorRawRecord $rawRecord): array;
}
