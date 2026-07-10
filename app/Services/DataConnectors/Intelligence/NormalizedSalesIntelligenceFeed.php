<?php

namespace App\Services\DataConnectors\Intelligence;

use App\Contracts\Connectors\Intelligence\SalesIntelligenceFeed;

class NormalizedSalesIntelligenceFeed extends AbstractNormalizedConnectorIntelligenceFeed implements SalesIntelligenceFeed
{
    public function key(): string
    {
        return 'sales_intelligence';
    }

    protected function supportedDatasetTypes(): array
    {
        return ['deal', 'deals', 'opportunit', 'sales', 'crm'];
    }

    protected function metricKeys(): array
    {
        return ['opportunities', 'pipeline_value', 'revenue', 'cpo', 'roas', 'influenced_pipeline', 'influenced_revenue'];
    }
}
