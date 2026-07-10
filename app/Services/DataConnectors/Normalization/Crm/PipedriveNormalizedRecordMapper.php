<?php

namespace App\Services\DataConnectors\Normalization\Crm;

class PipedriveNormalizedRecordMapper extends AbstractCrmNormalizedRecordMapper
{
    public function provider(): string
    {
        return 'pipedrive';
    }
}
