<?php

namespace App\Services\DataConnectors\Intelligence;

use App\Contracts\Connectors\Intelligence\LeadIntelligenceFeed;

class NormalizedLeadIntelligenceFeed extends AbstractNormalizedConnectorIntelligenceFeed implements LeadIntelligenceFeed
{
    public function key(): string
    {
        return 'lead_intelligence';
    }

    protected function supportedDatasetTypes(): array
    {
        return ['contact', 'contacts', 'lead', 'leads', 'crm'];
    }

    protected function metricKeys(): array
    {
        return ['leads', 'conversions', 'spend', 'cpl', 'cpa'];
    }
}
