<?php

namespace Database\Seeders;

use App\Models\LlmModel;
use App\Models\LlmProvider;
use App\Services\LlmModelRegistry;
use App\Services\LlmProviderRegistry;
use Illuminate\Database\Seeder;

class LlmProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = app(LlmProviderRegistry::class);
        $models = app(LlmModelRegistry::class);

        foreach ($providers->definitions() as $key => $definition) {
            $provider = LlmProvider::query()->updateOrCreate(
                ['provider' => $key],
                [
                    'name' => $definition['name'],
                    'status' => 'active',
                    'base_url' => $definition['base_url'] ?? null,
                    'api_key_env' => $definition['api_key_env'] ?? null,
                    'settings' => $definition['settings'] ?? null,
                ],
            );

            foreach ($models->definitions()[$key] ?? [] as $modelDefinition) {
                LlmModel::query()->updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'model' => $modelDefinition['model'],
                    ],
                    [
                        'name' => $modelDefinition['name'],
                        'type' => $modelDefinition['type'],
                        'context_window' => $modelDefinition['context_window'] ?? null,
                        'supports_json' => $modelDefinition['supports_json'] ?? false,
                        'supports_tools' => $modelDefinition['supports_tools'] ?? false,
                        'supports_vision' => $modelDefinition['supports_vision'] ?? false,
                        'supports_streaming' => $modelDefinition['supports_streaming'] ?? false,
                        'input_cost_per_1k' => $modelDefinition['input_cost_per_1k'] ?? null,
                        'output_cost_per_1k' => $modelDefinition['output_cost_per_1k'] ?? null,
                        'status' => $modelDefinition['status'] ?? 'active',
                        'metadata' => $modelDefinition['metadata'] ?? null,
                    ],
                );
            }
        }
    }
}
