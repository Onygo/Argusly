<?php

namespace App\Services\DataConnectors\Ads;

use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncCursor;
use Illuminate\Http\Client\Response;

class MetaAdsSyncAdapter extends AbstractAdsSyncAdapter
{
    protected function requestReport(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): Response
    {
        $dateRange = $this->dateRange($context);
        $accountId = trim((string) (data_get($context->plan->dataset->config_json, 'ad_account_id') ?: $context->plan->dataset->external_dataset_id));
        $accountId = str_starts_with($accountId, 'act_') ? $accountId : 'act_'.$accountId;

        return $this->http->get(
            $context->plan->account,
            $this->apiBaseUrl('meta_ads').'/'.$accountId.'/insights',
            [
                'fields' => 'date_start,date_stop,campaign_id,campaign_name,objective,adset_id,adset_name,ad_id,ad_name,impressions,clicks,spend,conversions,ctr,cpc,cpm',
                'time_range' => ['since' => $dateRange['start'], 'until' => $dateRange['end']],
                'level' => $context->plan->dataset->dataset_type === 'campaigns' ? 'campaign' : 'ad',
                'after' => $cursor->get('page_token'),
                'limit' => $context->plan->pageSize,
            ],
            timeout: $this->timeoutSeconds('meta_ads'),
        );
    }
}
