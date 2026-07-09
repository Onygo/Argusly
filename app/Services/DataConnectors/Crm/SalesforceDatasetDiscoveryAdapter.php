<?php

namespace App\Services\DataConnectors\Crm;

use App\Models\Connectors\ConnectorAccount;
use RuntimeException;

class SalesforceDatasetDiscoveryAdapter extends AbstractCrmDatasetDiscoveryAdapter
{
    public function providerKey(): string
    {
        return 'salesforce';
    }

    protected function objects(): array
    {
        return (array) config('data_connectors.providers.salesforce.config_json.crm.objects', []);
    }

    protected function schemaForObject(ConnectorAccount $account, string $objectKey, array $definition): array
    {
        $object = (string) ($definition['provider_object'] ?? $objectKey);
        $response = $this->http->get($account, $this->apiBaseUrl().'/sobjects/'.$object.'/describe', timeout: $this->timeoutSeconds());

        if (! $response->successful()) {
            throw new RuntimeException('Salesforce schema discovery failed for '.$object.' with status '.$response->status().'.');
        }

        return ['fields' => (array) $response->json('fields', [])];
    }

    protected function defaultCursorField(): string
    {
        return 'SystemModstamp';
    }
}
