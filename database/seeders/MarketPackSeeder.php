<?php

namespace Database\Seeders;

use App\Models\MarketPack;
use App\Models\MarketPackAlertTemplate;
use App\Models\MarketPackCompetitor;
use App\Models\MarketPackKeyword;
use App\Models\MarketPackMetric;
use App\Models\MarketPackScoringModel;
use App\Models\MarketPackSource;
use App\Models\MarketPackTheme;
use Illuminate\Database\Seeder;

class MarketPackSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->packs() as $definition) {
            $pack = MarketPack::query()->updateOrCreate([
                'key' => $definition['key'],
            ], [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'market_category' => $definition['market_category'],
                'status' => MarketPack::STATUS_ACTIVE,
                'version' => $definition['version'] ?? '1.0.0',
                'locale' => $definition['locale'] ?? 'en',
                'defaults_json' => $definition['defaults'] ?? [],
                'metadata_json' => [
                    'seeded_by' => static::class,
                    'manifest' => $definition['manifest'] ?? $definition['key'],
                ],
            ]);

            foreach ($definition['sources'] as $source) {
                MarketPackSource::query()->updateOrCreate([
                    'market_pack_id' => $pack->id,
                    'key' => $source['key'],
                ], [
                    'name' => $source['name'],
                    'source_type' => $source['source_type'],
                    'base_url' => $source['base_url'] ?? null,
                    'domain' => $source['domain'] ?? null,
                    'status' => $source['status'] ?? 'active',
                    'trust_level' => $source['trust_level'] ?? 3,
                    'authority_score' => $source['authority_score'] ?? 50,
                    'polling_frequency' => $source['polling_frequency'] ?? 'daily',
                    'crawl_policy_json' => $source['crawl_policy_json'] ?? ['allow_discovery' => true, 'respect_robots' => true],
                    'fetch_config_json' => $source['fetch_config_json'] ?? ['timeout_seconds' => 10],
                    'discovery_config_json' => $source['discovery_config_json'] ?? [],
                    'metadata_json' => $source['metadata_json'] ?? [],
                ]);
            }

            foreach ($definition['competitors'] as $competitor) {
                MarketPackCompetitor::query()->updateOrCreate([
                    'market_pack_id' => $pack->id,
                    'key' => $competitor['key'],
                ], [
                    'name' => $competitor['name'],
                    'domain' => $competitor['domain'] ?? null,
                    'aliases_json' => $competitor['aliases'] ?? [],
                    'metadata_json' => $competitor['metadata'] ?? [],
                ]);
            }

            $themes = [];
            foreach ($definition['themes'] as $theme) {
                $themes[$theme['key']] = MarketPackTheme::query()->updateOrCreate([
                    'market_pack_id' => $pack->id,
                    'key' => $theme['key'],
                ], [
                    'name' => $theme['name'],
                    'description' => $theme['description'] ?? null,
                    'weight' => $theme['weight'] ?? 1,
                    'metadata_json' => $theme['metadata'] ?? [],
                ]);
            }

            foreach ($definition['keywords'] as $keyword) {
                $theme = $themes[$keyword['theme']] ?? null;
                MarketPackKeyword::query()->updateOrCreate([
                    'market_pack_id' => $pack->id,
                    'market_pack_theme_id' => $theme?->id,
                    'keyword' => $keyword['keyword'],
                ], [
                    'keyword_type' => $keyword['keyword_type'] ?? 'theme',
                    'intent' => $keyword['intent'] ?? null,
                    'weight' => $keyword['weight'] ?? 1,
                    'metadata_json' => $keyword['metadata'] ?? [],
                ]);
            }

            foreach ($definition['metrics'] as $metric) {
                MarketPackMetric::query()->updateOrCreate([
                    'market_pack_id' => $pack->id,
                    'key' => $metric['key'],
                ], [
                    'name' => $metric['name'],
                    'metric_type' => $metric['metric_type'] ?? 'score',
                    'default_value' => $metric['default_value'] ?? null,
                    'unit' => $metric['unit'] ?? null,
                    'direction' => $metric['direction'] ?? null,
                    'weight' => $metric['weight'] ?? 1,
                    'metadata_json' => $metric['metadata'] ?? [],
                ]);
            }

            foreach ($definition['alert_templates'] as $template) {
                MarketPackAlertTemplate::query()->updateOrCreate([
                    'market_pack_id' => $pack->id,
                    'key' => $template['key'],
                ], [
                    'name' => $template['name'],
                    'trigger' => $template['trigger'],
                    'conditions_json' => $template['conditions'] ?? [],
                    'cooldown_minutes' => $template['cooldown_minutes'] ?? 60,
                    'severity' => $template['severity'] ?? 'medium',
                    'is_active' => $template['is_active'] ?? true,
                    'metadata_json' => $template['metadata'] ?? [],
                ]);
            }

            foreach ($definition['scoring_models'] as $model) {
                MarketPackScoringModel::query()->updateOrCreate([
                    'market_pack_id' => $pack->id,
                    'key' => $model['key'],
                ], [
                    'name' => $model['name'],
                    'model_type' => $model['model_type'] ?? 'page_pr_value',
                    'model_version' => $model['model_version'] ?? '1.0.0',
                    'weights_json' => $model['weights'] ?? [],
                    'defaults_json' => $model['defaults'] ?? [],
                    'metadata_json' => $model['metadata'] ?? [],
                ]);
            }
        }
    }

    private function packs(): array
    {
        $paths = glob(database_path('market-packs/*.php')) ?: [];
        sort($paths);

        return collect($paths)
            ->map(fn (string $path): array => require $path)
            ->values()
            ->all();
    }
}
