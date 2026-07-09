<?php

namespace Database\Factories\Connectors;

use App\Models\Connectors\ConnectorProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorProvider>
 */
class ConnectorProviderFactory extends Factory
{
    protected $model = ConnectorProvider::class;

    public function definition(): array
    {
        $key = fake()->unique()->slug(2);

        return [
            'provider_key' => str_replace('-', '_', $key),
            'name' => fake()->company().' Connector',
            'category' => ConnectorProvider::CATEGORY_OTHER,
            'status' => ConnectorProvider::STATUS_ACTIVE,
            'config_json' => [],
            'supports_oauth' => true,
            'supports_sync' => true,
            'supports_webhooks' => false,
        ];
    }
}
