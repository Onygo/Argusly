<?php

namespace App\Services\DataConnectors\Ads;

use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncCursor;
use Illuminate\Http\Client\Response;

class MicrosoftAdsSyncAdapter extends AbstractAdsSyncAdapter
{
    protected function requestReport(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): Response
    {
        $dateRange = $this->dateRange($context);

        return $this->http->post(
            $context->plan->account,
            $this->apiBaseUrl('microsoft_ads').'/reports',
            [
                'account_id' => data_get($context->plan->dataset->config_json, 'ad_account_id') ?: $context->plan->dataset->external_dataset_id,
                'dataset_type' => $context->plan->dataset->dataset_type,
                'start_date' => $dateRange['start'],
                'end_date' => $dateRange['end'],
                'page_token' => $cursor->get('page_token'),
                'page_size' => $context->plan->pageSize,
            ],
            timeout: $this->timeoutSeconds('microsoft_ads'),
        );
    }
}
