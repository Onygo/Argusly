<?php

namespace Database\Factories\Connectors;

use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorRawRecord;
use App\Models\Connectors\ConnectorSyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorRawRecord>
 */
class ConnectorRawRecordFactory extends Factory
{
    protected $model = ConnectorRawRecord::class;

    public function definition(): array
    {
        $dataset = ConnectorDataset::query()->first() ?? ConnectorDataset::factory()->create();
        $run = ConnectorSyncRun::query()->where('connector_dataset_id', $dataset->id)->first()
            ?? ConnectorSyncRun::factory()->create([
                'connector_account_id' => $dataset->connector_account_id,
                'connector_dataset_id' => $dataset->id,
                'workspace_id' => $dataset->workspace_id,
                'client_site_id' => $dataset->client_site_id,
                'provider_key' => $dataset->provider_key,
                'dataset_key' => $dataset->dataset_key,
            ]);

        return [
            'workspace_id' => $dataset->workspace_id,
            'client_site_id' => $dataset->client_site_id,
            'connector_provider_id' => $dataset->account->connector_provider_id,
            'connector_account_id' => $dataset->connector_account_id,
            'connector_dataset_id' => $dataset->id,
            'connector_sync_run_id' => $run->id,
            'provider_key' => $dataset->provider_key,
            'dataset_key' => $dataset->dataset_key,
            'record_type' => $dataset->dataset_type,
            'external_record_id' => fake()->uuid(),
            'fingerprint' => hash('sha256', fake()->uuid()),
            'period_start' => now()->startOfDay(),
            'period_end' => now()->endOfDay(),
            'observed_at' => now(),
            'payload_json' => ['value' => fake()->randomNumber()],
            'metadata_json' => [],
        ];
    }
}
