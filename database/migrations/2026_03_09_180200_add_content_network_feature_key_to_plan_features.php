<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const FEATURE_KEY = 'content_network_analysis_enabled';

    public function up(): void
    {
        if (! Schema::hasTable('plan_features')) {
            return;
        }

        $plans = Plan::query()->get();

        foreach ($plans as $plan) {
            $slug = strtolower((string) ($plan->slug ?: $plan->key));
            $value = $this->valueForPlan($slug);

            $existing = DB::table('plan_features')
                ->where('plan_id', $plan->id)
                ->where('feature_key', self::FEATURE_KEY)
                ->first();

            $payload = [
                'plan_id' => $plan->id,
                'feature_key' => self::FEATURE_KEY,
                'label' => 'Content network analysis enabled',
                'feature_group' => 'Content intelligence',
                'is_highlight' => false,
                'sort_order' => 136,
                'value_type' => 'bool',
                'value_bool' => $value,
                'value_int' => null,
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

    public function down(): void
    {
        if (! Schema::hasTable('plan_features')) {
            return;
        }

        DB::table('plan_features')
            ->where('feature_key', self::FEATURE_KEY)
            ->delete();
    }

    private function valueForPlan(string $slug): bool
    {
        return match ($slug) {
            'starter' => false,
            'growth', 'scale', 'enterprise' => true,
            default => true,
        };
    }
};
