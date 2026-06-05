<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const QUOTA_FEATURES = [
        'topics_seed_keywords_limit',
        'articles_per_month_limit',
        'llm_tracking_queries_per_month_limit',
        'competitor_slots_limit',
        'seo_audit_crawl_pages_per_month_limit',
        'languages_limit',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('plan_features')) {
            return;
        }

        $plans = Plan::query()->get();

        foreach ($plans as $plan) {
            $limits = is_array($plan->limits) ? $plan->limits : [];
            $slug = (string) ($plan->slug ?: $plan->key);

            $defaults = match ($slug) {
                'starter' => [
                    'topics_seed_keywords_limit' => 50,
                    'articles_per_month_limit' => (int) ($limits['included_drafts_per_month'] ?? 5),
                    'llm_tracking_queries_per_month_limit' => 150,
                    'competitor_slots_limit' => 3,
                    'seo_audit_crawl_pages_per_month_limit' => 300,
                    'languages_limit' => 1,
                ],
                'growth' => [
                    'topics_seed_keywords_limit' => 200,
                    'articles_per_month_limit' => (int) ($limits['included_drafts_per_month'] ?? 20),
                    'llm_tracking_queries_per_month_limit' => 600,
                    'competitor_slots_limit' => 10,
                    'seo_audit_crawl_pages_per_month_limit' => 1200,
                    'languages_limit' => 2,
                ],
                'scale' => [
                    'topics_seed_keywords_limit' => 1000,
                    'articles_per_month_limit' => (int) ($limits['included_drafts_per_month'] ?? 75),
                    'llm_tracking_queries_per_month_limit' => 2500,
                    'competitor_slots_limit' => 25,
                    'seo_audit_crawl_pages_per_month_limit' => 5000,
                    'languages_limit' => 5,
                ],
                'enterprise' => [
                    'topics_seed_keywords_limit' => -1,
                    'articles_per_month_limit' => -1,
                    'llm_tracking_queries_per_month_limit' => -1,
                    'competitor_slots_limit' => -1,
                    'seo_audit_crawl_pages_per_month_limit' => -1,
                    'languages_limit' => -1,
                ],
                default => [
                    'topics_seed_keywords_limit' => (int) ($limits['topics_seed_keywords_limit'] ?? -1),
                    'articles_per_month_limit' => (int) ($limits['articles_per_month_limit'] ?? ($limits['included_drafts_per_month'] ?? -1)),
                    'llm_tracking_queries_per_month_limit' => (int) ($limits['llm_tracking_queries_per_month_limit'] ?? -1),
                    'competitor_slots_limit' => (int) ($limits['competitor_slots_limit'] ?? -1),
                    'seo_audit_crawl_pages_per_month_limit' => (int) ($limits['seo_audit_crawl_pages_per_month_limit'] ?? -1),
                    'languages_limit' => (int) ($limits['languages_limit'] ?? -1),
                ],
            };

            foreach (self::QUOTA_FEATURES as $featureKey) {
                $value = (int) ($defaults[$featureKey] ?? -1);

                $existing = DB::table('plan_features')
                    ->where('plan_id', $plan->id)
                    ->where('feature_key', $featureKey)
                    ->first();

                if ($existing) {
                    DB::table('plan_features')
                        ->where('id', $existing->id)
                        ->update([
                            'value_type' => 'int',
                            'value_bool' => null,
                            'value_int' => $value,
                            'value_string' => null,
                            'value_json' => null,
                            'updated_at' => now(),
                        ]);
                    continue;
                }

                DB::table('plan_features')->insert([
                    'id' => (string) Str::uuid(),
                    'plan_id' => $plan->id,
                    'feature_key' => $featureKey,
                    'label' => $this->featureLabel($featureKey),
                    'feature_group' => 'Quota',
                    'is_highlight' => false,
                    'sort_order' => 900,
                    'value_type' => 'int',
                    'value_bool' => null,
                    'value_int' => $value,
                    'value_string' => null,
                    'value_json' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('plan_features')) {
            return;
        }

        DB::table('plan_features')
            ->whereIn('feature_key', self::QUOTA_FEATURES)
            ->delete();
    }

    private function featureLabel(string $featureKey): string
    {
        return match ($featureKey) {
            'topics_seed_keywords_limit' => 'Topics / seed keywords limit',
            'articles_per_month_limit' => 'Articles per month limit',
            'llm_tracking_queries_per_month_limit' => 'LLM tracking queries per month limit',
            'competitor_slots_limit' => 'Competitor slots limit',
            'seo_audit_crawl_pages_per_month_limit' => 'SEO audit crawl pages per month limit',
            'languages_limit' => 'Languages limit',
            default => $featureKey,
        };
    }
};
