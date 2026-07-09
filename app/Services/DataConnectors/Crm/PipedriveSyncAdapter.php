<?php

namespace App\Services\DataConnectors\Crm;

use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncCursor;
use Illuminate\Http\Client\Response;

class PipedriveSyncAdapter extends AbstractCrmSyncAdapter
{
    protected function requestObjects(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): Response
    {
        $endpoint = (string) data_get($context->plan->dataset->config_json, 'provider_object', $context->plan->dataset->dataset_type);

        return $this->http->get(
            $context->plan->account,
            $this->apiBaseUrl('pipedrive').'/'.trim($endpoint, '/'),
            [
                'start' => $cursor->get('start', 0),
                'limit' => $context->plan->pageSize,
                'updated_since' => $cursor->get('last_updated_at'),
            ],
            timeout: $this->timeoutSeconds('pipedrive'),
        );
    }
}
