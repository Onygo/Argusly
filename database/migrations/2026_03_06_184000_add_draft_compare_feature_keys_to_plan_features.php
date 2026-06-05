<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const FEATURE_DEFINITIONS = [
        'draft_compare_enabled' => ['type' => 'bool', 'label' => 'Draft Compare enabled', 'group' => 'AI'],
        'draft_compare_max_models' => ['type' => 'int', 'label' => 'Draft Compare max models', 'group' => 'AI'],
        'draft_compare_hybrid_enabled' => ['type' => 'bool', 'label' => 'Draft Compare hybrid enabled', 'group' => 'AI'],
        'draft_compare_scoring_enabled' => ['type' => 'bool', 'label' => 'Draft Compare scoring enabled', 'group' => 'AI'],
        'draft_compare_premium_models_enabled' => ['type' => 'bool', 'label' => 'Draft Compare premium models enabled', 'group' => 'AI'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('plan_features')) {
            return;
        }

        $plans = Plan::query()->get();
        foreach ($plans as $plan) {
            $defaults = $this->defaultsForPlan((string) ($plan->slug ?: $plan->key));

            foreach (self::FEATURE_DEFINITIONS as $featureKey => $definition) {
                $existing = DB::table('plan_features')
                    ->where('plan_id', $plan->id)
                    ->where('feature_key', $featureKey)
                    ->first();

                $payload = [
                    'plan_id' => $plan->id,
                    'feature_key' => $featureKey,
                    'label' => $definition['label'],
                    'feature_group' => $definition['group'],
                    'is_highlight' => false,
                    'sort_order' => 910,
                    'value_type' => $definition['type'],
                    'value_bool' => $definition['type'] === 'bool' ? (bool) $defaults[$featureKey] : null,
                    'value_int' => $definition['type'] === 'int' ? (int) $defaults[$featureKey] : null,
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
            ->whereIn('feature_key', array_keys(self::FEATURE_DEFINITIONS))
            ->delete();
    }

    /**
     * @return array<string,bool|int>
     */
    private function defaultsForPlan(string $planSlug): array
    {
        return match (strtolower(trim($planSlug))) {
            'starter' => [
                'draft_compare_enabled' => false,
                'draft_compare_max_models' => 1,
                'draft_compare_hybrid_enabled' => false,
                'draft_compare_scoring_enabled' => false,
                'draft_compare_premium_models_enabled' => false,
            ],
            'growth' => [
                'draft_compare_enabled' => true,
                'draft_compare_max_models' => 2,
                'draft_compare_hybrid_enabled' => false,
                'draft_compare_scoring_enabled' => true,
                'draft_compare_premium_models_enabled' => false,
            ],
            'scale' => [
                'draft_compare_enabled' => true,
                'draft_compare_max_models' => 4,
                'draft_compare_hybrid_enabled' => true,
                'draft_compare_scoring_enabled' => true,
                'draft_compare_premium_models_enabled' => true,
            ],
            'enterprise' => [
                'draft_compare_enabled' => true,
                'draft_compare_max_models' => 8,
                'draft_compare_hybrid_enabled' => true,
                'draft_compare_scoring_enabled' => true,
                'draft_compare_premium_models_enabled' => true,
            ],
            default => [
                'draft_compare_enabled' => true,
                'draft_compare_max_models' => 2,
                'draft_compare_hybrid_enabled' => false,
                'draft_compare_scoring_enabled' => true,
                'draft_compare_premium_models_enabled' => false,
            ],
        };
    }
};
