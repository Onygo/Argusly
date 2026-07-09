<?php

namespace App\Services\DataConnectors\Ads;

use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncCursor;
use Illuminate\Http\Client\Response;

class GoogleAdsSyncAdapter extends AbstractAdsSyncAdapter
{
    protected function requestReport(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): Response
    {
        $customerId = trim((string) (data_get($context->plan->dataset->config_json, 'ad_account_id') ?: $context->plan->dataset->external_dataset_id));
        $dateRange = $this->dateRange($context);

        return $this->http->post(
            $context->plan->account,
            $this->apiBaseUrl('google_ads').'/customers/'.$customerId.'/googleAds:search',
            [
                'query' => data_get($context->plan->dataset->sync_config_json, 'query')
                    ?: "SELECT segments.date, campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, ad_group.id, ad_group.name, ad_group.status, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.ctr, metrics.average_cpc, metrics.average_cpm FROM ad_group_ad WHERE segments.date BETWEEN '{$dateRange['start']}' AND '{$dateRange['end']}'",
                'pageToken' => $cursor->get('page_token'),
                'pageSize' => $context->plan->pageSize,
            ],
            timeout: $this->timeoutSeconds('google_ads'),
        );
    }
}
