<?php

namespace Database\Factories;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\MarketingMetricDefinition;
use App\Models\MarketingObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingObservation>
 */
class MarketingObservationFactory extends Factory
{
    protected $model = MarketingObservation::class;

    public function definition(): array
    {
        $account = ConnectorAccount::query()->first() ?? ConnectorAccount::factory()->create();
        $dataset = ConnectorDataset::query()->where('connector_account_id', $account->id)->first()
            ?? ConnectorDataset::factory()->create([
                'connector_account_id' => $account->id,
                'workspace_id' => $account->workspace_id,
                'client_site_id' => $account->client_site_id,
                'provider_key' => $account->provider_key,
            ]);
        $syncRun = ConnectorSyncRun::query()->where('connector_dataset_id', $dataset->id)->first()
            ?? ConnectorSyncRun::factory()->create([
                'connector_account_id' => $account->id,
                'connector_dataset_id' => $dataset->id,
                'workspace_id' => $account->workspace_id,
                'client_site_id' => $account->client_site_id,
                'provider_key' => $account->provider_key,
                'dataset_key' => $dataset->dataset_key,
            ]);
        $metric = MarketingMetricDefinition::query()->first()
            ?? MarketingMetricDefinition::factory()->create();

        $attributes = [
            'workspace_id' => $account->workspace_id,
            'client_site_id' => $account->client_site_id,
            'connector_provider_id' => $account->connector_provider_id,
            'connector_account_id' => $account->id,
            'connector_dataset_id' => $dataset->id,
            'connector_sync_run_id' => $syncRun->id,
            'marketing_metric_definition_id' => $metric->id,
            'metric_key' => $metric->metric_key,
            'metric_value' => fake()->randomFloat(4, 1, 1000),
            'unit' => $metric->default_unit,
            'period_start' => now()->startOfDay(),
            'period_end' => now()->endOfDay(),
            'granularity' => MarketingObservation::GRANULARITY_DAILY,
            'observed_at' => now(),
            'confidence_score' => 1,
            'quality_score' => 1,
            'external_id' => fake()->uuid(),
            'source_metadata_json' => [],
            'quality_metadata_json' => [],
            'raw_metadata_json' => [],
            'raw_payload_ref' => null,
        ];

        return array_merge($attributes, [
            'fingerprint' => MarketingObservation::fingerprintFor($attributes),
        ]);
    }
}
