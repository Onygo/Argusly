<?php

namespace App\Services\DataConnectors\Crm;

use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncCursor;
use Illuminate\Http\Client\Response;

class SalesforceSyncAdapter extends AbstractCrmSyncAdapter
{
    protected function requestObjects(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): Response
    {
        $object = (string) data_get($context->plan->dataset->config_json, 'provider_object', $context->plan->dataset->dataset_type);
        $watermark = (string) $cursor->get('last_updated_at', '1970-01-01T00:00:00Z');

        return $this->http->get(
            $context->plan->account,
            $this->apiBaseUrl('salesforce').'/query',
            [
                'q' => "SELECT FIELDS(ALL) FROM {$object} WHERE SystemModstamp > {$watermark} ORDER BY SystemModstamp ASC LIMIT {$context->plan->pageSize}",
                'next' => $cursor->get('page_token'),
            ],
            timeout: $this->timeoutSeconds('salesforce'),
        );
    }
}
