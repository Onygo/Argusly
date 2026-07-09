<?php

namespace App\Services\DataConnectors\Ads;

use App\Models\Connectors\ConnectorAccount;
use RuntimeException;

class GoogleAdsDatasetDiscoveryAdapter extends AbstractAdsDatasetDiscoveryAdapter
{
    public function providerKey(): string
    {
        return 'google_ads';
    }

    protected function discoverAdAccounts(ConnectorAccount $account): array
    {
        $response = $this->http->get($account, $this->apiBaseUrl().'/customers:listAccessibleCustomers', timeout: $this->timeoutSeconds());

        if (! $response->successful()) {
            throw new RuntimeException('Google Ads account discovery failed with status '.$response->status().'.');
        }

        $items = (array) ($response->json('customers')
            ?: $response->json('customerClients')
            ?: $response->json('results')
            ?: []);

        $resourceNames = (array) $response->json('resourceNames', []);

        foreach ($resourceNames as $resourceName) {
            $items[] = ['resourceName' => $resourceName];
        }

        return array_values(array_filter($items, fn (mixed $item): bool => is_array($item)));
    }
}
