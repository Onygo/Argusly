<?php

namespace App\Services\DataConnectors\Crm;

use App\Models\Connectors\ConnectorAccount;
use RuntimeException;

class PipedriveDatasetDiscoveryAdapter extends AbstractCrmDatasetDiscoveryAdapter
{
    public function providerKey(): string
    {
        return 'pipedrive';
    }

    protected function objects(): array
    {
        return (array) config('data_connectors.providers.pipedrive.config_json.crm.objects', []);
    }

    protected function schemaForObject(ConnectorAccount $account, string $objectKey, array $definition): array
    {
        $endpoint = (string) ($definition['fields_endpoint'] ?? $objectKey.'Fields');
        $response = $this->http->get($account, $this->apiBaseUrl().'/'.trim($endpoint, '/'), timeout: $this->timeoutSeconds());

        if (! $response->successful()) {
            throw new RuntimeException('Pipedrive schema discovery failed for '.$objectKey.' with status '.$response->status().'.');
        }

        return ['fields' => (array) $response->json('data', [])];
    }

    protected function defaultCursorField(): string
    {
        return 'update_time';
    }
}
