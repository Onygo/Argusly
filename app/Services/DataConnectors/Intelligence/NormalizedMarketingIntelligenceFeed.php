<?php

namespace App\Services\DataConnectors\Intelligence;

use App\Contracts\Connectors\Intelligence\MarketingIntelligenceFeed;

class NormalizedMarketingIntelligenceFeed extends AbstractNormalizedConnectorIntelligenceFeed implements MarketingIntelligenceFeed
{
    public function key(): string
    {
        return 'marketing_intelligence';
    }

    protected function supportedDatasetTypes(): array
    {
        return ['ads', 'campaign', 'performance', 'google_ads', 'microsoft_ads', 'meta_ads'];
    }
}
