<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const FEATURES = [
        'research_enabled' => ['type' => 'bool', 'group' => 'Research', 'label' => 'Research enabled', 'sort' => 130],
        'research_max_sources_per_project' => ['type' => 'int', 'group' => 'Research', 'label' => 'Research max sources per project', 'sort' => 131],
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
                $value = $this->valueForPlan($slug, $key);
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
                    DB::table('plan_features')->where('id', $existing->id)->update($payload);

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

    private function valueForPlan(string $slug, string $feature): bool|int
    {
        $matrix = [
            'starter' => [
                'research_enabled' => false,
                'research_max_sources_per_project' => 5,
            ],
            'growth' => [
                'research_enabled' => true,
                'research_max_sources_per_project' => 15,
            ],
            'scale' => [
                'research_enabled' => true,
                'research_max_sources_per_project' => 40,
            ],
            'enterprise' => [
                'research_enabled' => true,
                'research_max_sources_per_project' => 150,
            ],
        ];

        $defaults = [
            'research_enabled' => true,
            'research_max_sources_per_project' => 20,
        ];

        return $matrix[$slug][$feature] ?? $defaults[$feature];
    }
};
