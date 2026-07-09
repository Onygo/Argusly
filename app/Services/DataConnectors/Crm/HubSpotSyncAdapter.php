<?php

namespace App\Services\DataConnectors\Crm;

use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncCursor;
use Illuminate\Http\Client\Response;

class HubSpotSyncAdapter extends AbstractCrmSyncAdapter
{
    protected function requestObjects(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): Response
    {
        $object = (string) data_get($context->plan->dataset->config_json, 'provider_object', $context->plan->dataset->dataset_type);

        return $this->http->post(
            $context->plan->account,
            $this->apiBaseUrl('hubspot').'/crm/v3/objects/'.$object.'/search',
            [
                'limit' => $context->plan->pageSize,
                'after' => $cursor->get('after'),
                'sorts' => [['propertyName' => 'updatedAt', 'direction' => 'ASCENDING']],
                'properties' => data_get($context->plan->dataset->metadata_json, 'fields.*.name', []),
            ],
            timeout: $this->timeoutSeconds('hubspot'),
        );
    }
}
