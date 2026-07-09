<?php

namespace App\Services\DataConnectors\Ads;

use App\Models\Connectors\ConnectorAccount;
use App\Services\DataConnectors\ConnectorDatasetDiscoveryAdapter;
use App\Services\DataConnectors\ConnectorProviderHttpClient;
use App\Support\MarketingMetadataRedactor;
use Illuminate\Support\Str;

abstract class AbstractAdsDatasetDiscoveryAdapter implements ConnectorDatasetDiscoveryAdapter
{
    public function __construct(protected readonly ConnectorProviderHttpClient $http)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function discoverDatasets(ConnectorAccount $account): array
    {
        $adAccounts = collect($this->discoverAdAccounts($account))
            ->map(fn (array $item): array => $this->normalizeAdAccount($item))
            ->filter(fn (array $item): bool => $item['id'] !== '')
            ->values()
            ->all();

        $account->forceFill([
            'metadata_json' => array_merge((array) ($account->metadata_json ?? []), [
                'account_hierarchy' => $adAccounts,
                'hierarchy_discovered_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return collect($adAccounts)
            ->flatMap(fn (array $adAccount): array => $this->datasetsForAdAccount($adAccount))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    abstract protected function discoverAdAccounts(ConnectorAccount $account): array;

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    protected function normalizeAdAccount(array $item): array
    {
        $id = trim((string) (
            $item['id']
            ?? $item['account_id']
            ?? $item['accountId']
            ?? $item['customer_id']
            ?? $item['customerId']
            ?? $item['resourceName']
            ?? $item['resource_name']
            ?? ''
        ));

        if (str_contains($id, '/')) {
            $parts = explode('/', trim($id, '/'));
            $id = (string) end($parts);
        }

        $name = trim((string) (
            $item['name']
            ?? $item['display_name']
            ?? $item['displayName']
            ?? $item['descriptive_name']
            ?? $item['descriptiveName']
            ?? ''
        ));

        return [
            'id' => $id,
            'name' => $name !== '' ? $name : Str::headline($this->providerKey()).' '.$id,
            'currency' => (string) ($item['currency'] ?? $item['currencyCode'] ?? $item['currency_code'] ?? ''),
            'status' => (string) ($item['status'] ?? $item['account_status'] ?? ''),
            'parent_id' => (string) ($item['parent_id'] ?? $item['managerCustomerId'] ?? $item['manager_customer_id'] ?? ''),
            'raw' => MarketingMetadataRedactor::redact($item),
        ];
    }

    /**
     * @param array<string, mixed> $adAccount
     * @return array<int, array<string, mixed>>
     */
    protected function datasetsForAdAccount(array $adAccount): array
    {
        $id = (string) $adAccount['id'];
        $name = (string) $adAccount['name'];
        $prefix = $this->providerKey().':'.$id;

        $datasets = [
            [
                'external_dataset_id' => $id,
                'dataset_type' => 'ad_account',
                'display_name' => $name,
                'capabilities' => ['ads.account', 'ads.account_hierarchy'],
                'config' => [
                    'ad_account_id' => $id,
                    'account_id' => $id,
                ],
                'metadata' => [
                    'provider' => $this->providerKey(),
                    'account' => $adAccount,
                ],
            ],
            [
                'external_dataset_id' => $prefix.':campaigns',
                'dataset_type' => 'campaigns',
                'display_name' => $name.' Campaigns',
                'capabilities' => ['ads.campaigns', 'ads.campaign_status', 'ads.campaign_objective'],
                'sync_frequency' => 'daily',
                'config' => ['ad_account_id' => $id, 'entity' => 'campaigns'],
                'metadata' => ['provider' => $this->providerKey(), 'parent_account' => $adAccount],
            ],
            [
                'external_dataset_id' => $prefix.':'.$this->adGroupDatasetType(),
                'dataset_type' => $this->adGroupDatasetType(),
                'display_name' => $name.' '.$this->adGroupDisplayName(),
                'capabilities' => ['ads.ad_group_performance', 'ads.ad_sets', 'ads.ad_groups'],
                'sync_frequency' => 'daily',
                'config' => ['ad_account_id' => $id, 'entity' => $this->adGroupDatasetType()],
                'metadata' => ['provider' => $this->providerKey(), 'parent_account' => $adAccount],
            ],
            [
                'external_dataset_id' => $prefix.':ads',
                'dataset_type' => 'ads',
                'display_name' => $name.' Ads',
                'capabilities' => ['ads.ads'],
                'sync_frequency' => 'daily',
                'config' => ['ad_account_id' => $id, 'entity' => 'ads'],
                'metadata' => ['provider' => $this->providerKey(), 'parent_account' => $adAccount],
            ],
            [
                'external_dataset_id' => $prefix.':creatives',
                'dataset_type' => 'creatives',
                'display_name' => $name.' Creatives',
                'capabilities' => ['ads.creatives'],
                'sync_frequency' => 'daily',
                'config' => ['ad_account_id' => $id, 'entity' => 'creatives'],
                'metadata' => ['provider' => $this->providerKey(), 'parent_account' => $adAccount],
            ],
            [
                'external_dataset_id' => $prefix.':daily_performance',
                'dataset_type' => 'ads_daily_performance',
                'display_name' => $name.' Daily Performance',
                'capabilities' => ['ads.performance', 'ads.daily_history', 'ads.async_reports'],
                'sync_frequency' => 'daily',
                'sync_config' => [
                    'metrics' => ['impressions', 'clicks', 'cost', 'conversions', 'ctr', 'cpc', 'cpm'],
                    'dimensions' => ['date', 'campaign', 'ad_group', 'ad', 'creative'],
                    'supports_async_reports' => true,
                ],
                'config' => ['ad_account_id' => $id, 'entity' => 'daily_performance'],
                'metadata' => ['provider' => $this->providerKey(), 'parent_account' => $adAccount],
            ],
        ];

        return array_map(fn (array $dataset): array => array_merge($dataset, [
            'metadata' => MarketingMetadataRedactor::redact((array) ($dataset['metadata'] ?? [])),
        ]), $datasets);
    }

    protected function adGroupDatasetType(): string
    {
        return 'ad_groups';
    }

    protected function adGroupDisplayName(): string
    {
        return 'Ad Groups';
    }

    protected function apiBaseUrl(): string
    {
        return rtrim((string) config('data_connectors.providers.'.$this->providerKey().'.config_json.api.base_url'), '/');
    }

    protected function timeoutSeconds(): int
    {
        return max(1, (int) config('data_connectors.providers.'.$this->providerKey().'.config_json.api.timeout_seconds', 15));
    }
}
