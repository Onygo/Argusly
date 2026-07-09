<?php

namespace Database\Factories\Connectors;

use App\Models\Connectors\ConnectorCredential;
use App\Models\Connectors\ConnectorProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorCredential>
 */
class ConnectorCredentialFactory extends Factory
{
    protected $model = ConnectorCredential::class;

    public function definition(): array
    {
        $provider = ConnectorProvider::query()->first()
            ?? ConnectorProvider::factory()->create(['provider_key' => 'factory_provider']);

        return [
            'workspace_id' => null,
            'connector_provider_id' => $provider->id,
            'credential_type' => ConnectorCredential::TYPE_OAUTH_CLIENT,
            'name' => 'Factory OAuth Client',
            'encrypted_config' => [
                'client_id' => fake()->uuid(),
                'client_secret' => fake()->sha256(),
            ],
            'status' => 'active',
            'metadata_json' => [],
        ];
    }
}
