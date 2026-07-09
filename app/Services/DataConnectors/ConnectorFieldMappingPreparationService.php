<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorFieldMappingPreparation;

class ConnectorFieldMappingPreparationService
{
    /**
     * @param iterable<int, ConnectorDataset>|null $datasets
     */
    public function prepare(ConnectorAccount $account, ?iterable $datasets = null): int
    {
        $datasets ??= $account->datasets()->get();
        $count = 0;

        foreach ($datasets as $dataset) {
            if (! $dataset instanceof ConnectorDataset || ! $this->shouldPrepare($dataset)) {
                continue;
            }

            ConnectorFieldMappingPreparation::query()->updateOrCreate(
                [
                    'connector_account_id' => $account->id,
                    'object_key' => $dataset->dataset_type,
                ],
                [
                    'workspace_id' => $account->workspace_id,
                    'connector_dataset_id' => $dataset->id,
                    'provider_key' => $account->provider_key,
                    'status' => ConnectorFieldMappingPreparation::STATUS_PREPARED,
                    'source_fields_json' => (array) data_get($dataset->metadata_json, 'fields', []),
                    'target_preview_json' => [
                        'raw_records_only' => true,
                        'normalization_phase' => 29,
                    ],
                    'metadata_json' => [
                        'dataset_key' => $dataset->dataset_key,
                        'provider_key' => $dataset->provider_key,
                    ],
                    'prepared_at' => now(),
                ],
            );

            $count++;
        }

        return $count;
    }

    private function shouldPrepare(ConnectorDataset $dataset): bool
    {
        return str_starts_with((string) $dataset->provider_key, 'hubspot')
            || in_array((string) $dataset->provider_key, ['salesforce', 'pipedrive'], true)
            || $dataset->hasCapability('crm.field_mapping_prep');
    }
}
