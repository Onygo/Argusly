<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const FEATURES = [
        'brief_intelligence_enabled' => ['type' => 'bool', 'group' => 'Brief intelligence', 'label' => 'Brief intelligence enabled', 'sort' => 132],
        'brief_templates_enabled' => ['type' => 'bool', 'group' => 'Brief intelligence', 'label' => 'Brief templates enabled', 'sort' => 133],
        'brief_intelligence_billing_enabled' => ['type' => 'bool', 'group' => 'Brief intelligence', 'label' => 'Brief intelligence billing enabled', 'sort' => 134],
        'brief_intelligence_credits_per_run' => ['type' => 'int', 'group' => 'Brief intelligence', 'label' => 'Brief intelligence credits per run', 'sort' => 135],
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
                'brief_intelligence_enabled' => false,
                'brief_templates_enabled' => false,
                'brief_intelligence_billing_enabled' => false,
                'brief_intelligence_credits_per_run' => 0,
            ],
            'growth' => [
                'brief_intelligence_enabled' => true,
                'brief_templates_enabled' => false,
                'brief_intelligence_billing_enabled' => false,
                'brief_intelligence_credits_per_run' => 0,
            ],
            'scale' => [
                'brief_intelligence_enabled' => true,
                'brief_templates_enabled' => true,
                'brief_intelligence_billing_enabled' => false,
                'brief_intelligence_credits_per_run' => 0,
            ],
            'enterprise' => [
                'brief_intelligence_enabled' => true,
                'brief_templates_enabled' => true,
                'brief_intelligence_billing_enabled' => false,
                'brief_intelligence_credits_per_run' => 0,
            ],
        ];

        $defaults = [
            'brief_intelligence_enabled' => true,
            'brief_templates_enabled' => false,
            'brief_intelligence_billing_enabled' => false,
            'brief_intelligence_credits_per_run' => 0,
        ];

        return $matrix[$slug][$feature] ?? $defaults[$feature];
    }
};
