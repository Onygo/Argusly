<?php

namespace Database\Seeders;

use App\Models\Integration;
use Illuminate\Database\Seeder;

class IntegrationCatalogSeeder extends Seeder
{
    /**
     * Seed available integration providers.
     */
    public function run(): void
    {
        foreach (config('integrations.providers', []) as $key => $definition) {
            Integration::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => $definition['name'],
                    'auth_type' => $definition['auth_type'],
                    'default_scopes' => $definition['scopes'] ?? [],
                    'supports_refresh_tokens' => $definition['auth_type'] === 'oauth2',
                    'is_active' => true,
                    'is_system' => true,
                ],
            );
        }
    }
}
