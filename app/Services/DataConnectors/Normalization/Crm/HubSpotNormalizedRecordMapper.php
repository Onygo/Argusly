<?php

namespace App\Services\DataConnectors\Normalization\Crm;

class HubSpotNormalizedRecordMapper extends AbstractCrmNormalizedRecordMapper
{
    public function provider(): string
    {
        return 'hubspot';
    }
}
