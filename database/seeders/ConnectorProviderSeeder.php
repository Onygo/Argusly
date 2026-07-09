<?php

namespace Database\Seeders;

use App\Models\Connectors\ConnectorProvider;
use Illuminate\Database\Seeder;

class ConnectorProviderSeeder extends Seeder
{
    public function run(): void
    {
        foreach ((array) config('data_connectors.providers', []) as $providerKey => $definition) {
            ConnectorProvider::query()->updateOrCreate(
                ['provider_key' => (string) ($definition['provider_key'] ?? $providerKey)],
                [
                    'name' => (string) $definition['name'],
                    'category' => (string) ($definition['category'] ?? ConnectorProvider::CATEGORY_OTHER),
                    'status' => (string) ($definition['status'] ?? ConnectorProvider::STATUS_ACTIVE),
                    'config_json' => (array) ($definition['config_json'] ?? []),
                    'supports_oauth' => (bool) ($definition['supports_oauth'] ?? false),
                    'supports_sync' => (bool) ($definition['supports_sync'] ?? false),
                    'supports_webhooks' => (bool) ($definition['supports_webhooks'] ?? false),
                ]
            );
        }
    }
}
