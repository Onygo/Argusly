<?php

namespace App\Services\DataConnectors\Normalization\Crm;

class SalesforceNormalizedRecordMapper extends AbstractCrmNormalizedRecordMapper
{
    public function provider(): string
    {
        return 'salesforce';
    }
}
