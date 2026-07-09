<?php

namespace App\Services\DataConnectors\Crm;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorFieldMappingPreparation;
use App\Services\DataConnectors\ConnectorDatasetDiscoveryAdapter;
use App\Services\DataConnectors\ConnectorProviderHttpClient;
use App\Support\MarketingMetadataRedactor;

abstract class AbstractCrmDatasetDiscoveryAdapter implements ConnectorDatasetDiscoveryAdapter
{
    public function __construct(protected readonly ConnectorProviderHttpClient $http)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function discoverDatasets(ConnectorAccount $account): array
    {
        $datasets = [];
        $overview = [];

        foreach ($this->objects() as $objectKey => $definition) {
            $schema = $this->schemaForObject($account, (string) $objectKey, (array) $definition);
            $fields = $this->fieldsFromSchema($schema);

            $overview[(string) $objectKey] = [
                'display_name' => (string) ($definition['display_name'] ?? $objectKey),
                'field_count' => count($fields),
                'supports_incremental' => true,
            ];

            $datasets[] = [
                'external_dataset_id' => $this->providerKey().':'.$objectKey,
                'dataset_type' => (string) $objectKey,
                'display_name' => (string) ($definition['display_name'] ?? str($objectKey)->headline()),
                'capabilities' => ['crm.object', 'crm.incremental_sync', 'crm.field_mapping_prep'],
                'sync_frequency' => 'daily',
                'sync_config' => [
                    'object' => (string) $objectKey,
                    'provider_object' => (string) ($definition['provider_object'] ?? $objectKey),
                    'cursor_field' => (string) ($definition['cursor_field'] ?? $this->defaultCursorField()),
                    'sync_mode' => 'cursor_incremental',
                ],
                'config' => [
                    'object' => (string) $objectKey,
                    'provider_object' => (string) ($definition['provider_object'] ?? $objectKey),
                ],
                'metadata' => MarketingMetadataRedactor::redact([
                    'provider' => $this->providerKey(),
                    'schema' => $schema,
                    'fields' => $fields,
                    'webhook_registration' => [
                        'prepared' => $this->supportsWebhooks(),
                        'registered' => false,
                    ],
                    'field_mapping' => [
                        'prepared' => true,
                        'status' => ConnectorFieldMappingPreparation::STATUS_PREPARED,
                    ],
                ]),
            ];

            $this->prepareFieldMapping($account, (string) $objectKey, $fields, $schema);
        }

        $account->forceFill([
            'metadata_json' => array_merge((array) ($account->metadata_json ?? []), [
                'crm_object_overview' => $overview,
                'schema_discovered_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return $datasets;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    abstract protected function objects(): array;

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    abstract protected function schemaForObject(ConnectorAccount $account, string $objectKey, array $definition): array;

    /**
     * @param array<string, mixed> $schema
     * @return array<int, array<string, mixed>>
     */
    protected function fieldsFromSchema(array $schema): array
    {
        $fields = $schema['fields'] ?? $schema['properties'] ?? $schema['results'] ?? [];

        if (isset($schema['fields']) && is_array($schema['fields'])) {
            $fields = $schema['fields'];
        }

        return collect((array) $fields)
            ->map(function (mixed $field): array {
                $field = is_array($field) ? $field : ['name' => (string) $field];

                return [
                    'name' => (string) ($field['name'] ?? $field['apiName'] ?? $field['key'] ?? $field['id'] ?? ''),
                    'label' => (string) ($field['label'] ?? $field['displayName'] ?? $field['name'] ?? ''),
                    'type' => (string) ($field['type'] ?? $field['dataType'] ?? $field['fieldType'] ?? ''),
                    'custom' => (bool) ($field['custom'] ?? $field['isCustom'] ?? false),
                ];
            })
            ->filter(fn (array $field): bool => $field['name'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $schema
     */
    protected function prepareFieldMapping(ConnectorAccount $account, string $objectKey, array $fields, array $schema): void
    {
        ConnectorFieldMappingPreparation::query()->updateOrCreate(
            [
                'connector_account_id' => $account->id,
                'object_key' => $objectKey,
            ],
            [
                'workspace_id' => $account->workspace_id,
                'connector_dataset_id' => null,
                'provider_key' => $account->provider_key,
                'status' => ConnectorFieldMappingPreparation::STATUS_PREPARED,
                'source_fields_json' => MarketingMetadataRedactor::redact($fields),
                'target_preview_json' => [
                    'normalized_tables_planned' => false,
                    'phase_29_targets' => ['crm_contacts', 'crm_companies', 'crm_deals', 'crm_activities'],
                ],
                'metadata_json' => MarketingMetadataRedactor::redact([
                    'schema' => $schema,
                    'prepared_by' => class_basename(static::class),
                ]),
                'prepared_at' => now(),
            ],
        );
    }

    protected function defaultCursorField(): string
    {
        return 'updated_at';
    }

    protected function supportsWebhooks(): bool
    {
        return (bool) config('data_connectors.providers.'.$this->providerKey().'.supports_webhooks', false);
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
