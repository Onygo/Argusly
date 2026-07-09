<?php

namespace App\Services\DataConnectors\Ads;

use App\Models\Connectors\ConnectorAccount;
use RuntimeException;

class MicrosoftAdsDatasetDiscoveryAdapter extends AbstractAdsDatasetDiscoveryAdapter
{
    public function providerKey(): string
    {
        return 'microsoft_ads';
    }

    protected function discoverAdAccounts(ConnectorAccount $account): array
    {
        $response = $this->http->get($account, $this->apiBaseUrl().'/customers', timeout: $this->timeoutSeconds());

        if (! $response->successful()) {
            throw new RuntimeException('Microsoft Ads account discovery failed with status '.$response->status().'.');
        }

        return array_values(array_filter((array) (
            $response->json('accounts')
            ?: $response->json('Customers')
            ?: $response->json('value')
            ?: $response->json('data')
            ?: []
        ), fn (mixed $item): bool => is_array($item)));
    }
}
