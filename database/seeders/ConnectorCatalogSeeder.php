<?php

namespace Database\Seeders;

use App\Models\ConnectorCapability;
use App\Models\ConnectorManifest;
use Illuminate\Database\Seeder;

class ConnectorCatalogSeeder extends Seeder
{
    /**
     * Seed Argusly-side connector manifests, versions and capability declarations.
     */
    public function run(): void
    {
        foreach (config('connectors.manifests', []) as $key => $definition) {
            $manifest = ConnectorManifest::query()->updateOrCreate(
                ['key' => $key],
                [
                    'type' => $definition['type'],
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'status' => 'active',
                    'is_system' => true,
                ],
            );

            $version = $manifest->versions()->updateOrCreate(
                ['version' => $definition['version']],
                [
                    'status' => 'active',
                    'metadata' => ['managed_by' => 'argusly'],
                ],
            );

            foreach ($definition['capabilities'] ?? [] as $capability) {
                ConnectorCapability::query()->updateOrCreate(
                    [
                        'connector_manifest_id' => $manifest->id,
                        'connector_version_id' => $version->id,
                        'capability' => $capability,
                    ],
                    ['is_enabled' => true],
                );
            }
        }
    }
}
