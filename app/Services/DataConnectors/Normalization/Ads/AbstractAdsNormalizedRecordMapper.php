<?php

namespace App\Services\DataConnectors\Normalization\Ads;

use App\Contracts\Connectors\Normalization\NormalizedRecordMapper;
use App\Data\Connectors\NormalizedRecord;
use App\Models\Connectors\ConnectorRawRecord;
use App\Services\DataConnectors\Normalization\AbstractNormalizedRecordMapper;

abstract class AbstractAdsNormalizedRecordMapper extends AbstractNormalizedRecordMapper implements NormalizedRecordMapper
{
    /**
     * @return array<int, NormalizedRecord>
     */
    public function map(ConnectorRawRecord $rawRecord): array
    {
        $payload = $this->payload($rawRecord);
        $records = [];

        if ($this->isAccountRecord($rawRecord, $payload)) {
            $account = $this->marketingAccount($rawRecord, $payload);
            if ($account instanceof NormalizedRecord) {
                $records[] = $account;
            }
        }

        $campaign = $this->campaign($rawRecord, $payload);
        if ($campaign instanceof NormalizedRecord) {
            $records[] = $campaign;
        }

        $adGroup = $this->adGroup($rawRecord, $payload);
        if ($adGroup instanceof NormalizedRecord) {
            $records[] = $adGroup;
        }

        $ad = $this->ad($rawRecord, $payload);
        if ($ad instanceof NormalizedRecord) {
            $records[] = $ad;
        }

        $performance = $this->dailyPerformance($rawRecord, $payload);
        if ($performance instanceof NormalizedRecord) {
            $records[] = $performance;
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function isAccountRecord(ConnectorRawRecord $rawRecord, array $payload): bool
    {
        return in_array((string) $rawRecord->record_type, ['ad_account', 'ad_accounts', 'account', 'accounts'], true)
            || in_array((string) data_get($rawRecord->dataset, 'dataset_type'), ['ad_account', 'ad_accounts'], true)
            || $this->string($payload, ['account.id', 'customer.id', 'account_id', 'customer_id', 'id']) !== null
                && $this->string($payload, ['campaign.id', 'campaign_id', 'CampaignId']) === null
                && $this->string($payload, ['metrics.impressions', 'impressions', 'Impressions']) === null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function marketingAccount(ConnectorRawRecord $rawRecord, array $payload): ?NormalizedRecord
    {
        $providerAccountId = $this->providerAccountId($rawRecord, $payload);

        if ($providerAccountId === null) {
            return null;
        }

        return NormalizedRecord::make(NormalizedRecord::MARKETING_ACCOUNT, [
            'provider_account_id' => $providerAccountId,
            'name' => $this->string($payload, ['account.name', 'customer.descriptive_name', 'customer.descriptiveName', 'descriptive_name', 'name', 'account_name']),
            'status' => $this->string($payload, ['account.status', 'customer.status', 'status', 'account_status']),
            'currency' => $this->string($payload, ['account.currency', 'customer.currency_code', 'currency_code', 'currency', 'currencyCode']),
            'timezone' => $this->string($payload, ['account.time_zone', 'account.timezone', 'timezone', 'time_zone']),
        ], $this->rawReference($rawRecord));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function campaign(ConnectorRawRecord $rawRecord, array $payload): ?NormalizedRecord
    {
        $campaignId = $this->campaignId($payload);

        if ($campaignId === null) {
            return null;
        }

        return NormalizedRecord::make(NormalizedRecord::CAMPAIGN, [
            'provider_campaign_id' => $campaignId,
            '_provider_account_id' => $this->providerAccountId($rawRecord, $payload),
            'name' => $this->string($payload, ['campaign.name', 'campaign_name', 'CampaignName', 'name']),
            'objective' => $this->string($payload, ['campaign.objective', 'objective', 'campaign.advertising_channel_type', 'campaign.advertisingChannelType', 'CampaignType']),
            'status' => $this->string($payload, ['campaign.status', 'campaign_status', 'CampaignStatus', 'status']),
            'start_date' => $this->date($payload, ['campaign.start_date', 'campaign.startDate', 'start_date', 'StartDate']),
            'end_date' => $this->date($payload, ['campaign.end_date', 'campaign.endDate', 'end_date', 'EndDate']),
            'budget' => $this->money($payload, ['campaign_budget.amount_micros', 'campaign.budget_micros', 'budget_micros', 'budget', 'Budget']),
            'currency' => $this->string($payload, ['currency', 'currency_code', 'CurrencyCode']),
        ], $this->rawReference($rawRecord));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function adGroup(ConnectorRawRecord $rawRecord, array $payload): ?NormalizedRecord
    {
        $adGroupId = $this->adGroupId($payload);

        if ($adGroupId === null) {
            return null;
        }

        return NormalizedRecord::make(NormalizedRecord::AD_GROUP, [
            'provider_ad_group_id' => $adGroupId,
            '_provider_campaign_id' => $this->campaignId($payload),
            'name' => $this->string($payload, ['ad_group.name', 'ad_group_name', 'adset_name', 'AdGroupName', 'name']),
            'status' => $this->string($payload, ['ad_group.status', 'ad_group_status', 'adset_status', 'AdGroupStatus', 'status']),
            'bid_strategy' => $this->string($payload, ['ad_group.type', 'bid_strategy', 'bidStrategyType', 'BiddingStrategyType']),
            'budget' => $this->money($payload, ['ad_group.budget_micros', 'daily_budget_micros', 'budget_micros', 'budget', 'Budget']),
        ], $this->rawReference($rawRecord));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function ad(ConnectorRawRecord $rawRecord, array $payload): ?NormalizedRecord
    {
        $adId = $this->adId($payload);

        if ($adId === null) {
            return null;
        }

        return NormalizedRecord::make(NormalizedRecord::AD, [
            'provider_ad_id' => $adId,
            '_provider_campaign_id' => $this->campaignId($payload),
            '_provider_ad_group_id' => $this->adGroupId($payload),
            'name' => $this->string($payload, ['ad.name', 'ad_name', 'AdName', 'name']),
            'status' => $this->string($payload, ['ad.status', 'ad_status', 'AdStatus', 'status']),
            'creative_type' => $this->string($payload, ['ad.type', 'creative_type', 'creative.type', 'AdType']),
            'landing_url' => $this->string($payload, ['ad.final_urls.0', 'ad.finalUrls.0', 'landing_url', 'destination_url', 'FinalUrl']),
        ], $this->rawReference($rawRecord));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function dailyPerformance(ConnectorRawRecord $rawRecord, array $payload): ?NormalizedRecord
    {
        if (! $this->hasPerformanceMetric($payload)) {
            return null;
        }

        [$entityType, $entityId] = $this->performanceEntity($rawRecord, $payload);

        if ($entityId === null) {
            return null;
        }

        $date = $this->date($payload, ['segments.date', 'date_start', 'date', 'day'], $rawRecord->period_start?->toDateString());

        if ($date === null) {
            return null;
        }

        return NormalizedRecord::make(NormalizedRecord::DAILY_PERFORMANCE, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'date' => $date,
            'impressions' => $this->integer($payload, ['metrics.impressions', 'impressions', 'Impressions']),
            'clicks' => $this->integer($payload, ['metrics.clicks', 'clicks', 'Clicks']),
            'cost' => $this->money($payload, ['metrics.cost_micros', 'metrics.costMicros', 'cost_micros', 'spend', 'cost', 'Cost']) ?? 0.0,
            'conversions' => $this->conversions($payload),
            'ctr' => $this->decimal($payload, ['metrics.ctr', 'ctr', 'Ctr']),
            'cpc' => $this->money($payload, ['metrics.average_cpc', 'metrics.averageCpc', 'average_cpc', 'cpc', 'AverageCpc']),
            'cpm' => $this->money($payload, ['metrics.average_cpm', 'metrics.averageCpm', 'average_cpm', 'cpm', 'AverageCpm']),
            'revenue' => $this->money($payload, ['revenue', 'Revenue', 'metrics.revenue_micros', 'metrics.revenueMicros']),
        ], $this->rawReference($rawRecord));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function providerAccountId(ConnectorRawRecord $rawRecord, array $payload): ?string
    {
        return $this->string($payload, [
            'account.id',
            'customer.id',
            'customer_id',
            'customerId',
            'account_id',
            'accountId',
            'AccountId',
            'account',
            'id',
        ]) ?: $this->string((array) ($rawRecord->dataset?->config_json ?? []), ['ad_account_id', 'account_id'])
            ?: $this->accountIdFromDataset($rawRecord);
    }

    protected function accountIdFromDataset(ConnectorRawRecord $rawRecord): ?string
    {
        $externalId = (string) ($rawRecord->dataset?->external_dataset_id ?? '');

        if ($externalId === '') {
            return null;
        }

        $parts = explode(':', $externalId);

        return count($parts) >= 2 ? $parts[1] : $externalId;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function campaignId(array $payload): ?string
    {
        return $this->string($payload, ['campaign.id', 'campaign_id', 'CampaignId', 'campaignId']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function adGroupId(array $payload): ?string
    {
        return $this->string($payload, ['ad_group.id', 'ad_group_id', 'adGroupId', 'adset_id', 'adset.id', 'AdGroupId']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function adId(array $payload): ?string
    {
        return $this->string($payload, ['ad.id', 'ad_id', 'adId', 'AdId']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: string|null}
     */
    protected function performanceEntity(ConnectorRawRecord $rawRecord, array $payload): array
    {
        if ($adId = $this->adId($payload)) {
            return ['ad', $adId];
        }

        if ($adGroupId = $this->adGroupId($payload)) {
            return ['ad_group', $adGroupId];
        }

        if ($campaignId = $this->campaignId($payload)) {
            return ['campaign', $campaignId];
        }

        return ['account', $this->providerAccountId($rawRecord, $payload)];
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function hasPerformanceMetric(array $payload): bool
    {
        return $this->value($payload, [
            'metrics.impressions',
            'impressions',
            'Impressions',
            'metrics.clicks',
            'clicks',
            'Clicks',
            'spend',
            'cost',
            'Cost',
        ]) !== null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $paths
     */
    protected function money(array $payload, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = $this->value($payload, [$path]);

            if (! $this->present($value)) {
                continue;
            }

            if (is_array($value)) {
                $value = $value['value'] ?? null;
            }

            if (! is_numeric($value)) {
                continue;
            }

            $value = (float) $value;

            return str_contains(strtolower($path), 'micros') ? $value / 1000000 : $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function conversions(array $payload): float
    {
        $value = $this->value($payload, ['metrics.conversions', 'conversions', 'Conversions']);

        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item): float => is_array($item) && is_numeric($item['value'] ?? null) ? (float) $item['value'] : 0.0)
                ->sum();
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
