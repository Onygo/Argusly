<?php

namespace Database\Factories\Connectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorDataset>
 */
class ConnectorDatasetFactory extends Factory
{
    protected $model = ConnectorDataset::class;

    public function definition(): array
    {
        $account = ConnectorAccount::query()->first() ?? ConnectorAccount::factory()->create();

        return [
            'connector_account_id' => $account->id,
            'workspace_id' => $account->workspace_id,
            'client_site_id' => $account->client_site_id,
            'provider_key' => $account->provider_key,
            'dataset_key' => 'dataset_'.fake()->unique()->word(),
            'dataset_type' => 'property',
            'external_dataset_id' => fake()->uuid(),
            'display_name' => fake()->domainName(),
            'status' => ConnectorDataset::STATUS_ACTIVE,
            'sync_frequency' => 'daily',
            'next_sync_at' => now()->addDay(),
            'last_sync_at' => null,
            'discovered_at' => now(),
            'last_seen_at' => now(),
            'deactivated_at' => null,
            'health_status' => 'healthy',
            'health_severity' => 'info',
            'latest_health_event_id' => null,
            'health_checked_at' => null,
            'cursor_json' => null,
            'capabilities_json' => [
                'keys' => ['metrics'],
                'definitions' => [
                    'metrics' => ['enabled' => true],
                ],
            ],
            'sync_config_json' => [],
            'config_json' => [],
            'metadata_json' => [],
        ];
    }
}
