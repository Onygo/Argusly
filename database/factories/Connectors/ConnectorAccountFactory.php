<?php

namespace Database\Factories\Connectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorAccount>
 */
class ConnectorAccountFactory extends Factory
{
    protected $model = ConnectorAccount::class;

    public function definition(): array
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'connector-factory-org'],
            ['name' => 'Connector Factory Organization', 'status' => 'active', 'approved_at' => now()]
        );

        $workspace = Workspace::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Connector Factory Workspace'],
            ['display_name' => 'Connector Factory Workspace']
        );

        $provider = ConnectorProvider::query()->first()
            ?? ConnectorProvider::factory()->create(['provider_key' => 'factory_provider']);

        return [
            'workspace_id' => $workspace->id,
            'client_site_id' => null,
            'connector_provider_id' => $provider->id,
            'provider_key' => $provider->provider_key,
            'account_name' => fake()->company().' Account',
            'external_account_id' => fake()->optional()->uuid(),
            'status' => ConnectorAccount::STATUS_CONNECTED,
            'connected_at' => now(),
            'disconnected_at' => null,
            'last_synced_at' => null,
            'sync_frequency' => null,
            'next_sync_at' => null,
            'health_status' => 'healthy',
            'health_severity' => 'info',
            'latest_health_event_id' => null,
            'health_checked_at' => null,
            'last_api_call_at' => null,
            'last_error' => null,
            'rate_limit_json' => [],
            'health_score' => 100,
            'metadata_json' => [],
        ];
    }
}
