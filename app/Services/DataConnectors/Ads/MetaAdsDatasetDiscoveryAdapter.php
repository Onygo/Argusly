<?php

namespace App\Services\DataConnectors\Ads;

use App\Models\Connectors\ConnectorAccount;
use RuntimeException;

class MetaAdsDatasetDiscoveryAdapter extends AbstractAdsDatasetDiscoveryAdapter
{
    public function providerKey(): string
    {
        return 'meta_ads';
    }

    protected function discoverAdAccounts(ConnectorAccount $account): array
    {
        $response = $this->http->get($account, $this->apiBaseUrl().'/me/adaccounts', [
            'fields' => 'id,name,account_id,account_status,currency,business',
            'limit' => $this->pageSize(),
        ], timeout: $this->timeoutSeconds());

        if (! $response->successful()) {
            throw new RuntimeException('Meta Ads account discovery failed with status '.$response->status().'.');
        }

        return array_values(array_filter((array) ($response->json('data', [])), fn (mixed $item): bool => is_array($item)));
    }

    protected function adGroupDatasetType(): string
    {
        return 'ad_sets';
    }

    protected function adGroupDisplayName(): string
    {
        return 'Ad Sets';
    }

    private function pageSize(): int
    {
        return max(1, min(500, (int) config('data_connectors.providers.meta_ads.config_json.api.page_size', 100)));
    }
}
