<?php

namespace App\Services\DataConnectors\Crm;

use App\Models\Connectors\ConnectorAccount;
use RuntimeException;

class HubSpotDatasetDiscoveryAdapter extends AbstractCrmDatasetDiscoveryAdapter
{
    public function providerKey(): string
    {
        return 'hubspot';
    }

    protected function objects(): array
    {
        return (array) config('data_connectors.providers.hubspot.config_json.crm.objects', []);
    }

    protected function schemaForObject(ConnectorAccount $account, string $objectKey, array $definition): array
    {
        $object = (string) ($definition['provider_object'] ?? $objectKey);
        $response = $this->http->get($account, $this->apiBaseUrl().'/crm/v3/properties/'.$object, timeout: $this->timeoutSeconds());

        if (! $response->successful()) {
            throw new RuntimeException('HubSpot schema discovery failed for '.$object.' with status '.$response->status().'.');
        }

        return ['properties' => (array) $response->json('results', [])];
    }

    protected function defaultCursorField(): string
    {
        return 'updatedAt';
    }
}
