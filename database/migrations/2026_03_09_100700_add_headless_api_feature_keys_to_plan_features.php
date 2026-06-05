<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const FEATURES = [
        'api_only_enabled' => ['type' => 'bool', 'default' => false, 'group' => 'API', 'label' => 'API-only mode enabled', 'sort' => 120],
        'api_webhooks_enabled' => ['type' => 'bool', 'default' => false, 'group' => 'API', 'label' => 'API webhooks enabled', 'sort' => 121],
        'api_analytics_ingest_enabled' => ['type' => 'bool', 'default' => false, 'group' => 'API', 'label' => 'API analytics ingest enabled', 'sort' => 122],
        'api_max_keys' => ['type' => 'int', 'default' => 1, 'group' => 'API', 'label' => 'Max API keys', 'sort' => 123],
        'api_max_destinations' => ['type' => 'int', 'default' => 1, 'group' => 'API', 'label' => 'Max content destinations', 'sort' => 124],
        'api_rate_limit_per_minute' => ['type' => 'int', 'default' => 60, 'group' => 'API', 'label' => 'API requests per minute', 'sort' => 125],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('plan_features')) {
            return;
        }

        $plans = Plan::query()->get();

        foreach ($plans as $plan) {
            $slug = strtolower((string) ($plan->slug ?: $plan->key));

            foreach (self::FEATURES as $key => $config) {
                $value = $this->valueForPlan($slug, $key, $config['default']);
                $existing = DB::table('plan_features')
                    ->where('plan_id', $plan->id)
                    ->where('feature_key', $key)
                    ->first();

                $payload = [
                    'plan_id' => $plan->id,
                    'feature_key' => $key,
                    'label' => $config['label'],
                    'feature_group' => $config['group'],
                    'is_highlight' => false,
                    'sort_order' => $config['sort'],
                    'value_type' => $config['type'],
                    'value_bool' => $config['type'] === 'bool' ? (bool) $value : null,
                    'value_int' => $config['type'] === 'int' ? (int) $value : null,
                    'value_string' => null,
                    'value_json' => null,
                    'updated_at' => now(),
                ];

                if ($existing) {
                    DB::table('plan_features')
                        ->where('id', $existing->id)
                        ->update($payload);

                    continue;
                }

                DB::table('plan_features')->insert(array_merge($payload, [
                    'id' => (string) Str::uuid(),
                    'created_at' => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('plan_features')) {
            return;
        }

        DB::table('plan_features')
            ->whereIn('feature_key', array_keys(self::FEATURES))
            ->delete();
    }

    private function valueForPlan(string $slug, string $featureKey, bool|int $default): bool|int
    {
        $matrix = [
            'starter' => [
                'api_only_enabled' => false,
                'api_webhooks_enabled' => false,
                'api_analytics_ingest_enabled' => false,
                'api_max_keys' => 1,
                'api_max_destinations' => 1,
                'api_rate_limit_per_minute' => 60,
            ],
            'growth' => [
                'api_only_enabled' => true,
                'api_webhooks_enabled' => true,
                'api_analytics_ingest_enabled' => true,
                'api_max_keys' => 5,
                'api_max_destinations' => 5,
                'api_rate_limit_per_minute' => 180,
            ],
            'scale' => [
                'api_only_enabled' => true,
                'api_webhooks_enabled' => true,
                'api_analytics_ingest_enabled' => true,
                'api_max_keys' => 20,
                'api_max_destinations' => 20,
                'api_rate_limit_per_minute' => 600,
            ],
            'enterprise' => [
                'api_only_enabled' => true,
                'api_webhooks_enabled' => true,
                'api_analytics_ingest_enabled' => true,
                'api_max_keys' => -1,
                'api_max_destinations' => -1,
                'api_rate_limit_per_minute' => 2000,
            ],
        ];

        return $matrix[$slug][$featureKey] ?? $default;
    }
};
