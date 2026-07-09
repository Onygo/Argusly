<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use RuntimeException;

class FakeDatasetDiscoveryAdapter implements ConnectorDatasetDiscoveryAdapter
{
    public function providerKey(): string
    {
        return (string) config('data_connectors.testing.fake_dataset_discovery.provider_key', 'fake_dataset_discovery');
    }

    public function discoverDatasets(ConnectorAccount $account): array
    {
        $failure = config('data_connectors.testing.fake_dataset_discovery.failure');

        if (is_string($failure) && trim($failure) !== '') {
            throw new RuntimeException($failure);
        }

        return array_values((array) config('data_connectors.testing.fake_dataset_discovery.datasets', []));
    }
}
