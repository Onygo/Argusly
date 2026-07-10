<?php

namespace App\Services\DataConnectors\Intelligence;

use App\Contracts\Connectors\Intelligence\CampaignIntelligenceFeed;

class NormalizedCampaignIntelligenceFeed extends AbstractNormalizedConnectorIntelligenceFeed implements CampaignIntelligenceFeed
{
    public function key(): string
    {
        return 'campaign_intelligence';
    }

    protected function supportedDatasetTypes(): array
    {
        return ['ads', 'campaign', 'ad_group', 'ad', 'performance'];
    }

    protected function metricKeys(): array
    {
        return ['impressions', 'clicks', 'ctr', 'cpc', 'cpm', 'spend', 'conversions', 'revenue', 'roas'];
    }
}
