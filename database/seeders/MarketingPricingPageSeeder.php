<?php

namespace Database\Seeders;

use App\Models\CreditPack;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\SiteSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketingPricingPageSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPlans();
        $this->seedCreditPacks();
        $this->seedPageContent();
    }

    private function seedPlans(): void
    {
        $existingIds = Plan::query()
            ->whereIn('slug', ['creator', 'growth', 'scale', 'enterprise', 'starter'])
            ->pluck('id', 'slug');

        $plans = [
            [
                'id' => (string) ($existingIds['creator'] ?? $existingIds['starter'] ?? Str::uuid()),
                'slug' => 'creator',
                'internal_code' => 'creator',
                'key' => 'creator',
                'name' => 'Creator',
                'description_short' => 'For solo creators and marketers building a consistent content operation.',
                'interval' => 'month',
                'price_monthly_cents' => 3900,
                'price_yearly_cents' => 39000,
                'monthly_price_cents' => 3900,
                'price_cents' => 3900,
                'currency' => 'EUR',
                'vat_included' => true,
                'included_credits' => 100,
                'included_credits_per_interval' => 100,
                'article_estimate_min' => 7,
                'article_estimate_max' => 10,
                'credit_rollover_policy' => 'limited',
                'credit_expiry_days' => 90,
                'credit_rollover_monthly_cycles' => 3,
                'workspace_limit' => 1,
                'user_limit' => 1,
                'seat_limit' => 1,
                'has_required_onboarding' => false,
                'onboarding_label' => null,
                'onboarding_checkout_label' => null,
                'onboarding_receipt_label' => null,
                'onboarding_description' => null,
                'onboarding_fee_cents' => 0,
                'onboarding_fee_currency' => 'EUR',
                'onboarding_display_mode' => null,
                'onboarding_is_visible_public' => false,
                'onboarding_sort_order' => 0,
                'is_active' => true,
                'is_public' => true,
                'billing_type' => 'fixed',
                'billing_provider_plan_key' => 'creator',
                'is_featured' => false,
                'is_popular' => false,
                'sort_order' => 1,
                'badge' => null,
                'cta_label' => 'Start Creator',
                'cta_href' => null,
                'limits' => [
                    'workspaces' => 1,
                    'sites' => 1,
                    'users' => 1,
                    'languages_limit' => 2,
                ],
            ],
            [
                'id' => (string) ($existingIds['growth'] ?? Str::uuid()),
                'slug' => 'growth',
                'internal_code' => 'growth',
                'key' => 'growth',
                'name' => 'Growth',
                'description_short' => 'For scaling marketing teams running multi-locale publishing and automation.',
                'interval' => 'month',
                'price_monthly_cents' => 14900,
                'price_yearly_cents' => 149000,
                'monthly_price_cents' => 14900,
                'price_cents' => 14900,
                'currency' => 'EUR',
                'vat_included' => true,
                'included_credits' => 500,
                'included_credits_per_interval' => 500,
                'article_estimate_min' => 35,
                'article_estimate_max' => 50,
                'credit_rollover_policy' => 'limited',
                'credit_expiry_days' => 90,
                'credit_rollover_monthly_cycles' => 3,
                'workspace_limit' => 2,
                'user_limit' => 5,
                'seat_limit' => 5,
                'has_required_onboarding' => false,
                'onboarding_label' => null,
                'onboarding_checkout_label' => null,
                'onboarding_receipt_label' => null,
                'onboarding_description' => null,
                'onboarding_fee_cents' => 0,
                'onboarding_fee_currency' => 'EUR',
                'onboarding_display_mode' => null,
                'onboarding_is_visible_public' => false,
                'onboarding_sort_order' => 0,
                'is_active' => true,
                'is_public' => true,
                'billing_type' => 'fixed',
                'billing_provider_plan_key' => 'growth',
                'is_featured' => true,
                'is_popular' => true,
                'sort_order' => 2,
                'badge' => 'Most popular',
                'cta_label' => 'Start Growth',
                'cta_href' => null,
                'limits' => [
                    'workspaces' => 2,
                    'sites' => 5,
                    'users' => 5,
                    'languages_limit' => 5,
                ],
            ],
            [
                'id' => (string) ($existingIds['scale'] ?? Str::uuid()),
                'slug' => 'scale',
                'internal_code' => 'scale',
                'key' => 'scale',
                'name' => 'Scale',
                'description_short' => 'For multi-brand teams scaling localized publishing with governance and automation.',
                'interval' => 'month',
                'price_monthly_cents' => 49900,
                'price_yearly_cents' => 499000,
                'monthly_price_cents' => 49900,
                'price_cents' => 49900,
                'currency' => 'EUR',
                'vat_included' => true,
                'included_credits' => 2000,
                'included_credits_per_interval' => 2000,
                'article_estimate_min' => 140,
                'article_estimate_max' => 200,
                'credit_rollover_policy' => 'limited',
                'credit_expiry_days' => 90,
                'credit_rollover_monthly_cycles' => 3,
                'workspace_limit' => 5,
                'user_limit' => 15,
                'seat_limit' => 15,
                'has_required_onboarding' => false,
                'onboarding_label' => null,
                'onboarding_checkout_label' => null,
                'onboarding_receipt_label' => null,
                'onboarding_description' => null,
                'onboarding_fee_cents' => 0,
                'onboarding_fee_currency' => 'EUR',
                'onboarding_display_mode' => null,
                'onboarding_is_visible_public' => false,
                'onboarding_sort_order' => 0,
                'is_active' => true,
                'is_public' => true,
                'billing_type' => 'fixed',
                'billing_provider_plan_key' => 'scale',
                'is_featured' => false,
                'is_popular' => false,
                'sort_order' => 3,
                'badge' => null,
                'cta_label' => 'Start Scale',
                'cta_href' => null,
                'limits' => [
                    'workspaces' => 5,
                    'sites' => 15,
                    'users' => 15,
                    'languages_limit' => -1,
                ],
            ],
            [
                'id' => (string) ($existingIds['enterprise'] ?? Str::uuid()),
                'slug' => 'enterprise',
                'internal_code' => 'enterprise',
                'key' => 'enterprise',
                'name' => 'Enterprise',
                'description_short' => 'Custom enterprise workflows, governance, integrations, and support.',
                'interval' => 'month',
                'price_monthly_cents' => null,
                'price_yearly_cents' => null,
                'monthly_price_cents' => 0,
                'price_cents' => 0,
                'currency' => 'EUR',
                'vat_included' => true,
                'included_credits' => 0,
                'included_credits_per_interval' => 0,
                'article_estimate_min' => null,
                'article_estimate_max' => null,
                'credit_rollover_policy' => 'limited',
                'credit_expiry_days' => 90,
                'credit_rollover_monthly_cycles' => 3,
                'workspace_limit' => null,
                'user_limit' => null,
                'seat_limit' => 0,
                'has_required_onboarding' => false,
                'onboarding_label' => null,
                'onboarding_checkout_label' => null,
                'onboarding_receipt_label' => null,
                'onboarding_description' => null,
                'onboarding_fee_cents' => null,
                'onboarding_fee_currency' => 'EUR',
                'onboarding_display_mode' => null,
                'onboarding_is_visible_public' => false,
                'onboarding_sort_order' => 0,
                'is_active' => true,
                'is_public' => true,
                'billing_type' => 'custom',
                'billing_provider_plan_key' => 'enterprise',
                'is_featured' => false,
                'is_popular' => false,
                'sort_order' => 4,
                'badge' => 'Custom',
                'cta_label' => 'Talk to sales',
                'cta_href' => '/contact?subject=enterprise-pricing#contact-form',
                'limits' => [],
            ],
        ];

        $plans = array_map(function (array $plan): array {
            $plan['limits'] = json_encode($plan['limits'] ?? [], JSON_UNESCAPED_SLASHES);

            return $plan;
        }, $plans);

        Plan::query()
            ->upsert(
                $plans,
                ['id'],
                [
                    'slug',
                    'internal_code',
                    'key',
                    'name',
                    'description_short',
                    'interval',
                    'price_monthly_cents',
                    'price_yearly_cents',
                    'monthly_price_cents',
                    'price_cents',
                    'currency',
                    'vat_included',
                    'included_credits',
                    'included_credits_per_interval',
                    'article_estimate_min',
                    'article_estimate_max',
                    'credit_rollover_policy',
                    'credit_expiry_days',
                    'credit_rollover_monthly_cycles',
                    'limits',
                    'workspace_limit',
                    'user_limit',
                    'seat_limit',
                    'has_required_onboarding',
                    'onboarding_label',
                    'onboarding_checkout_label',
                    'onboarding_receipt_label',
                    'onboarding_description',
                    'onboarding_fee_cents',
                    'onboarding_fee_currency',
                    'onboarding_display_mode',
                    'onboarding_is_visible_public',
                    'onboarding_sort_order',
                    'is_active',
                    'is_public',
                    'billing_type',
                    'billing_provider_plan_key',
                    'is_featured',
                    'is_popular',
                    'sort_order',
                    'badge',
                    'cta_label',
                    'cta_href',
                    'updated_at',
                ]
            );

        Plan::query()
            ->where('billing_type', 'fixed')
            ->where('is_public', true)
            ->update([
                'is_featured' => false,
                'is_popular' => false,
                'badge' => DB::raw("CASE WHEN badge = 'Most popular' THEN NULL ELSE badge END"),
            ]);

        Plan::query()
            ->where('slug', 'growth')
            ->update([
                'is_featured' => true,
                'is_popular' => true,
                'badge' => 'Most popular',
            ]);

        $featureRows = [
            'creator' => [
                ['feature_key' => 'basic_ai_optimization', 'label' => 'Basic AI optimization', 'feature_group' => 'Optimization', 'is_highlight' => true, 'sort_order' => 10],
                ['feature_key' => 'wordpress_connector', 'label' => 'WordPress publishing', 'feature_group' => 'Publishing', 'is_highlight' => true, 'sort_order' => 20],
                ['feature_key' => 'structured_answer_blocks', 'label' => 'Structured answer blocks', 'feature_group' => 'AI visibility', 'is_highlight' => true, 'sort_order' => 30],
                ['feature_key' => 'content_calendar', 'label' => 'Content calendar', 'feature_group' => 'Planning', 'is_highlight' => true, 'sort_order' => 40],
                ['feature_key' => 'email_support', 'label' => 'Email support', 'feature_group' => 'Support', 'is_highlight' => true, 'sort_order' => 50],
                ['feature_key' => 'draft_compare_enabled', 'label' => 'Draft Compare enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 170, 'value_type' => 'bool', 'value_bool' => false],
                ['feature_key' => 'draft_compare_max_models', 'label' => 'Draft Compare max models', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 171, 'value_type' => 'int', 'value_int' => 1],
                ['feature_key' => 'draft_compare_hybrid_enabled', 'label' => 'Draft Compare hybrid enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 172, 'value_type' => 'bool', 'value_bool' => false],
                ['feature_key' => 'draft_compare_scoring_enabled', 'label' => 'Draft Compare scoring enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 173, 'value_type' => 'bool', 'value_bool' => false],
                ['feature_key' => 'draft_compare_premium_models_enabled', 'label' => 'Draft Compare premium models enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 174, 'value_type' => 'bool', 'value_bool' => false],
                ['feature_key' => 'translation_enabled', 'label' => 'Translation enabled', 'feature_group' => 'Localization', 'is_highlight' => false, 'sort_order' => 150, 'value_type' => 'bool', 'value_bool' => false],
                ['feature_key' => 'automation_enabled', 'label' => 'Automation enabled', 'feature_group' => 'Automation', 'is_highlight' => false, 'sort_order' => 160, 'value_type' => 'bool', 'value_bool' => false],
            ],
            'growth' => [
                ['feature_key' => 'multi_locale_workflows', 'label' => 'Multi-locale workflows', 'feature_group' => 'Localization', 'is_highlight' => true, 'sort_order' => 10],
                ['feature_key' => 'ai_visibility_tracking', 'label' => 'AI visibility tracking', 'feature_group' => 'AI visibility', 'is_highlight' => true, 'sort_order' => 20],
                ['feature_key' => 'content_automations', 'label' => 'Content automations', 'feature_group' => 'Automation', 'is_highlight' => true, 'sort_order' => 30],
                ['feature_key' => 'team_collaboration', 'label' => 'Team collaboration', 'feature_group' => 'Collaboration', 'is_highlight' => true, 'sort_order' => 40],
                ['feature_key' => 'content_refresh_workflows', 'label' => 'Content refresh workflows', 'feature_group' => 'Lifecycle', 'is_highlight' => true, 'sort_order' => 50],
                ['feature_key' => 'api_enabled', 'label' => 'API publishing', 'feature_group' => 'Publishing', 'is_highlight' => true, 'sort_order' => 60, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'priority_support', 'label' => 'Priority support', 'feature_group' => 'Support', 'is_highlight' => true, 'sort_order' => 70, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'draft_compare_enabled', 'label' => 'Draft Compare enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 170, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'draft_compare_max_models', 'label' => 'Draft Compare max models', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 171, 'value_type' => 'int', 'value_int' => 3],
                ['feature_key' => 'draft_compare_hybrid_enabled', 'label' => 'Draft Compare hybrid enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 172, 'value_type' => 'bool', 'value_bool' => false],
                ['feature_key' => 'draft_compare_scoring_enabled', 'label' => 'Draft Compare scoring enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 173, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'draft_compare_premium_models_enabled', 'label' => 'Draft Compare premium models enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 174, 'value_type' => 'bool', 'value_bool' => false],
                ['feature_key' => 'translation_enabled', 'label' => 'Translation enabled', 'feature_group' => 'Localization', 'is_highlight' => false, 'sort_order' => 150, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'automation_enabled', 'label' => 'Automation enabled', 'feature_group' => 'Automation', 'is_highlight' => false, 'sort_order' => 160, 'value_type' => 'bool', 'value_bool' => true],
            ],
            'scale' => [
                ['feature_key' => 'multi_brand_workspaces', 'label' => 'Multi-brand workspaces', 'feature_group' => 'Organization', 'is_highlight' => true, 'sort_order' => 10],
                ['feature_key' => 'advanced_localization', 'label' => 'Advanced localization', 'feature_group' => 'Localization', 'is_highlight' => true, 'sort_order' => 20],
                ['feature_key' => 'approval_workflows', 'label' => 'Approval workflows', 'feature_group' => 'Governance', 'is_highlight' => true, 'sort_order' => 30],
                ['feature_key' => 'chained_content_strategy', 'label' => 'Chained content strategy', 'feature_group' => 'Strategy', 'is_highlight' => true, 'sort_order' => 40],
                ['feature_key' => 'content_lifecycle_management', 'label' => 'Content lifecycle management', 'feature_group' => 'Lifecycle', 'is_highlight' => true, 'sort_order' => 50],
                ['feature_key' => 'advanced_automations', 'label' => 'Advanced automations', 'feature_group' => 'Automation', 'is_highlight' => true, 'sort_order' => 60],
                ['feature_key' => 'audit_logs', 'label' => 'Audit logs', 'feature_group' => 'Governance', 'is_highlight' => true, 'sort_order' => 70],
                ['feature_key' => 'dedicated_onboarding', 'label' => 'Dedicated onboarding', 'feature_group' => 'Support', 'is_highlight' => true, 'sort_order' => 80],
                ['feature_key' => 'draft_compare_enabled', 'label' => 'Draft Compare enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 170, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'draft_compare_max_models', 'label' => 'Draft Compare max models', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 171, 'value_type' => 'int', 'value_int' => 4],
                ['feature_key' => 'draft_compare_hybrid_enabled', 'label' => 'Draft Compare hybrid enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 172, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'draft_compare_scoring_enabled', 'label' => 'Draft Compare scoring enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 173, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'draft_compare_premium_models_enabled', 'label' => 'Draft Compare premium models enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 174, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'api_enabled', 'label' => 'API enabled', 'feature_group' => 'Publishing', 'is_highlight' => false, 'sort_order' => 150, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'translation_enabled', 'label' => 'Translation enabled', 'feature_group' => 'Localization', 'is_highlight' => false, 'sort_order' => 160, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'automation_enabled', 'label' => 'Automation enabled', 'feature_group' => 'Automation', 'is_highlight' => false, 'sort_order' => 170, 'value_type' => 'bool', 'value_bool' => true],
            ],
            'enterprise' => [
                ['feature_key' => 'sso_enabled', 'label' => 'SSO and governance', 'feature_group' => 'Security', 'is_highlight' => true, 'sort_order' => 10, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'custom_ai_workflows', 'label' => 'Custom AI workflows', 'feature_group' => 'Automation', 'is_highlight' => true, 'sort_order' => 20],
                ['feature_key' => 'sla_support', 'label' => 'SLA support', 'feature_group' => 'Support', 'is_highlight' => true, 'sort_order' => 30],
                ['feature_key' => 'dedicated_infrastructure', 'label' => 'Dedicated infrastructure', 'feature_group' => 'Infrastructure', 'is_highlight' => true, 'sort_order' => 40],
                ['feature_key' => 'custom_integrations', 'label' => 'Custom integrations', 'feature_group' => 'Integration', 'is_highlight' => true, 'sort_order' => 50],
                ['feature_key' => 'advanced_permissions', 'label' => 'Advanced permissions', 'feature_group' => 'Governance', 'is_highlight' => true, 'sort_order' => 60],
                ['feature_key' => 'draft_compare_enabled', 'label' => 'Draft Compare enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 170, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'draft_compare_max_models', 'label' => 'Draft Compare max models', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 171, 'value_type' => 'int', 'value_int' => 8],
                ['feature_key' => 'draft_compare_hybrid_enabled', 'label' => 'Draft Compare hybrid enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 172, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'draft_compare_scoring_enabled', 'label' => 'Draft Compare scoring enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 173, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'draft_compare_premium_models_enabled', 'label' => 'Draft Compare premium models enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 174, 'value_type' => 'bool', 'value_bool' => true],
            ],
        ];

        foreach ($featureRows as $slug => $features) {
            $plan = Plan::query()->where('slug', $slug)->first();
            if (! $plan) {
                continue;
            }

            $existing = PlanFeature::query()
                ->where('plan_id', $plan->id)
                ->whereIn('feature_key', array_column($features, 'feature_key'))
                ->get()
                ->keyBy('feature_key');

            $payloads = array_map(function (array $feature) use ($existing, $plan): array {
                $valueType = (string) ($feature['value_type'] ?? 'bool');

                return [
                    'id' => (string) ($existing->get($feature['feature_key'])?->id ?? Str::uuid()),
                    'plan_id' => $plan->id,
                    'feature_key' => $feature['feature_key'],
                    'label' => $feature['label'],
                    'feature_group' => $feature['feature_group'],
                    'is_highlight' => (bool) $feature['is_highlight'],
                    'sort_order' => (int) $feature['sort_order'],
                    'value_type' => $valueType,
                    'value_bool' => $valueType === 'bool' ? (bool) ($feature['value_bool'] ?? true) : null,
                    'value_int' => $valueType === 'int' ? (int) ($feature['value_int'] ?? 0) : null,
                    'value_string' => null,
                    'value_json' => null,
                    'created_at' => $existing->get($feature['feature_key'])?->created_at ?? now(),
                    'updated_at' => now(),
                ];
            }, $features);

            PlanFeature::query()->upsert(
                $payloads,
                ['plan_id', 'feature_key'],
                [
                    'label',
                    'feature_group',
                    'is_highlight',
                    'sort_order',
                    'value_type',
                    'value_bool',
                    'value_int',
                    'value_string',
                    'value_json',
                    'updated_at',
                ]
            );
        }

        $this->cleanupLegacyPlans();
    }

    private function cleanupLegacyPlans(): void
    {
        $canonicalPlans = Plan::query()
            ->whereIn('slug', ['creator', 'growth', 'scale', 'enterprise'])
            ->get()
            ->keyBy('slug');

        $legacyToCanonical = [
            'starter' => 'creator',
            'pro' => 'growth',
            'agency' => 'scale',
        ];

        foreach ($legacyToCanonical as $legacyKey => $targetSlug) {
            $target = $canonicalPlans->get($targetSlug);
            if (! $target) {
                continue;
            }

            $legacyPlans = Plan::query()
                ->where(function ($query) use ($legacyKey): void {
                    $query->where('slug', $legacyKey)->orWhere('key', $legacyKey)->orWhere('internal_code', $legacyKey);
                })
                ->where('id', '!=', $target->id)
                ->get();

            foreach ($legacyPlans as $legacy) {
                DB::table('subscriptions')->where('plan_id', $legacy->id)->update(['plan_id' => $target->id]);
                if (\Schema::hasColumn('subscriptions', 'pending_plan_id')) {
                    DB::table('subscriptions')->where('pending_plan_id', $legacy->id)->update(['pending_plan_id' => $target->id]);
                }
                if (\Schema::hasTable('workspace_entitlements') && \Schema::hasColumn('workspace_entitlements', 'plan_id')) {
                    DB::table('workspace_entitlements')->where('plan_id', $legacy->id)->update(['plan_id' => $target->id]);
                }
                if (\Schema::hasTable('subscription_plan_changes')) {
                    DB::table('subscription_plan_changes')->where('from_plan_id', $legacy->id)->update(['from_plan_id' => $target->id]);
                    DB::table('subscription_plan_changes')->where('to_plan_id', $legacy->id)->update(['to_plan_id' => $target->id]);
                }

                $legacy->delete();
            }
        }
    }

    private function seedCreditPacks(): void
    {
        $packs = [
            [
                'key' => 'pack_100',
                'name' => '100 credits',
                'credits_amount' => 100,
                'price_cents' => 3900,
                'currency' => 'EUR',
                'vat_included' => true,
                'never_expires' => false,
                'expires_in_months' => 12,
                'meta' => [
                    'sort_order' => 10,
                    'description' => 'Flexible top-up for short-term demand peaks.',
                ],
            ],
            [
                'key' => 'pack_500',
                'name' => '500 credits',
                'credits_amount' => 500,
                'price_cents' => 17900,
                'currency' => 'EUR',
                'vat_included' => true,
                'never_expires' => false,
                'expires_in_months' => 12,
                'meta' => [
                    'sort_order' => 20,
                    'description' => 'Best for scaling campaigns and refresh workflows.',
                    'is_best_value' => true,
                ],
            ],
            [
                'key' => 'pack_1000',
                'name' => '1,000 credits',
                'credits_amount' => 1000,
                'price_cents' => 32900,
                'currency' => 'EUR',
                'vat_included' => true,
                'never_expires' => false,
                'expires_in_months' => 12,
                'meta' => [
                    'sort_order' => 30,
                    'description' => 'For multi-market launches and larger automation volumes.',
                ],
            ],
        ];

        foreach ($packs as $pack) {
            $row = CreditPack::query()->firstOrNew(['key' => $pack['key']]);
            if (! $row->exists) {
                $row->id = (string) Str::uuid();
            }

            $row->fill($pack);
            $row->is_active = true;
            $row->save();
        }

        CreditPack::query()
            ->whereNotIn('key', collect($packs)->pluck('key')->all())
            ->update(['is_active' => false]);
    }

    private function seedPageContent(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['key' => 'marketing_pricing_page'],
            ['value' => $this->pageContent()]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function pageContent(): array
    {
        return [
            'en' => [
                'hero' => [
                    'eyebrow' => 'Premium content operations',
                    'headline' => 'Scale content operations beyond AI writing',
                    'subheadline' => 'Plan, generate, optimize, localize and publish content from one platform.',
                    'supporting_text' => 'More than AI writing. PublishLayer manages the full content lifecycle.',
                    'primary_cta_label' => 'Choose a plan',
                    'secondary_cta_label' => 'Talk to sales',
                ],
                'plans' => [
                    [
                        'slug' => 'creator',
                        'eyebrow' => 'Solo operation',
                        'audience' => 'For solo creators and marketers',
                        'features' => [
                            '1 workspace',
                            'Basic AI optimization',
                            'WordPress publishing',
                            'Structured answer blocks',
                            'Content calendar',
                            'Email support',
                        ],
                        'cta_label' => 'Start Creator',
                    ],
                    [
                        'slug' => 'growth',
                        'eyebrow' => 'Team workflow',
                        'audience' => 'For scaling marketing teams',
                        'badge' => 'Most popular',
                        'features' => [
                            'Multi-locale workflows',
                            'AI visibility tracking',
                            'Content automations',
                            'Team collaboration',
                            'Content refresh workflows',
                            'API + WordPress publishing',
                            'Advanced optimization workflows',
                            'Priority support',
                        ],
                        'cta_label' => 'Start Growth',
                    ],
                    [
                        'slug' => 'scale',
                        'eyebrow' => 'Operational scale',
                        'audience' => 'For multi-brand and multi-locale operations',
                        'features' => [
                            'Multi-brand workspaces',
                            'Advanced localization',
                            'Approval workflows',
                            'Chained content strategy',
                            'AI visibility insights',
                            'Content lifecycle management',
                            'Advanced automations',
                            'Audit logs',
                            'Dedicated onboarding',
                        ],
                        'cta_label' => 'Start Scale',
                    ],
                ],
                'enterprise' => [
                    'badge' => 'Enterprise',
                    'price_label' => 'Custom pricing',
                    'audience' => 'Custom enterprise workflows and governance',
                    'features' => [
                        'Custom credit volume',
                        'SSO and governance',
                        'Custom AI workflows',
                        'SLA support',
                        'Dedicated infrastructure',
                        'Strategic onboarding',
                        'Custom integrations',
                        'Advanced permissions',
                        'Enterprise support',
                    ],
                    'cta_label' => 'Talk to sales',
                ],
                'comparison' => [
                    'title' => 'More than AI writing',
                    'subtitle' => 'PublishLayer helps teams manage the full content lifecycle from planning to publishing and AI discoverability.',
                    'left_label' => 'PublishLayer',
                    'right_label' => 'Traditional AI writers',
                    'rows' => [
                        ['label' => 'AI writing', 'publishlayer' => true, 'alternative' => true],
                        ['label' => 'Multi-locale publishing', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'Content automations', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'AI visibility tracking', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'Chained content strategy', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'Structured answer blocks', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'CMS publishing workflows', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'Editorial collaboration', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'Content lifecycle management', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'Multi-brand operations', 'publishlayer' => true, 'alternative' => false],
                    ],
                ],
                'credits' => [
                    'title' => 'Flexible AI credits',
                    'body' => 'Credits are consumed by generation, translations, refreshes, answer blocks, research and AI visibility workflows.',
                    'note' => 'A standard SEO article typically uses 10 to 14 credits depending on content depth, research and optimization workflows.',
                    'points' => [
                        'Subscription plans unlock features and include monthly credits.',
                        'Unused subscription credits roll over for 3 months.',
                        'Consumption order always uses subscription credits first and purchased credits second.',
                    ],
                ],
                'credit_packs' => [
                    'title' => 'Scale usage when needed',
                    'subtitle' => 'Add flexible credit packs anytime without upgrading your plan.',
                    'cards' => [
                        ['key' => 'pack_100', 'description' => 'Short-term top-up for extra production.', 'badge' => 'Flexible'],
                        ['key' => 'pack_500', 'description' => 'Best for seasonal campaigns and refresh cycles.', 'badge' => 'Best value'],
                        ['key' => 'pack_1000', 'description' => 'For heavier publishing operations across teams.', 'badge' => 'High volume'],
                    ],
                    'footer_note' => 'Purchased credit packs remain valid for 12 months and are shared across the workspace team.',
                    'custom_label' => 'Custom enterprise packs available',
                ],
                'team_workflow' => [
                    'title' => 'Built for teams, workflows and scale',
                    'subtitle' => 'Coordinate planning, approvals, localization, optimization and publishing without stitching together disconnected tools.',
                    'points' => [
                        'Editorial collaboration with structured handoffs',
                        'Approval and governance layers for production teams',
                        'Multi-locale delivery from one operational system',
                        'Publishing orchestration across CMS and API workflows',
                    ],
                ],
                'roi' => [
                    'title' => 'Replace fragmented content workflows',
                    'items' => [
                        'Reduce operational overhead',
                        'Centralize publishing',
                        'Automate localization',
                        'Improve AI discoverability',
                        'Scale content operations',
                        'Eliminate disconnected tooling',
                    ],
                ],
                'faq' => [
                    ['question' => 'Is PublishLayer just an AI writer?', 'answer' => 'No. PublishLayer is a content operations platform for planning, generation, optimization, localization, governance and publishing orchestration.'],
                    ['question' => 'How do credits work?', 'answer' => 'Credits power AI-assisted workflows such as generation, answer blocks, translations, refreshes, research and AI visibility scans.'],
                    ['question' => 'Can I buy extra credits?', 'answer' => 'Yes. Credit packs can be purchased separately without changing your plan.'],
                    ['question' => 'Can multiple team members collaborate?', 'answer' => 'Yes. Growth, Scale and Enterprise are designed for shared workflows, approvals and team collaboration.'],
                    ['question' => 'Can I publish directly to WordPress?', 'answer' => 'Yes. PublishLayer supports WordPress publishing workflows, and higher plans add API-driven delivery paths.'],
                    ['question' => 'Do unused credits expire?', 'answer' => 'Included subscription credits roll over for 3 months. Purchased credit packs remain valid for 12 months.'],
                    ['question' => 'Are credit packs shared across teams?', 'answer' => 'Purchased credits are shared across the workspace team and used after included subscription credits are consumed.'],
                ],
                'final_cta' => [
                    'title' => 'Move content operations into one scalable system',
                    'body' => 'Run planning, AI-assisted production, localization, optimization and publishing from one operational platform.',
                    'primary_label' => 'Choose your plan',
                    'secondary_label' => 'Talk to sales',
                ],
            ],
            'nl' => [
                'hero' => [
                    'eyebrow' => 'Premium content operations',
                    'headline' => 'Schaal content operations voorbij AI writing',
                    'subheadline' => 'Plan, genereer, optimaliseer, lokaliseer en publiceer content vanuit één platform.',
                    'supporting_text' => 'Meer dan AI writing. PublishLayer beheert de volledige content lifecycle.',
                    'primary_cta_label' => 'Kies een plan',
                    'secondary_cta_label' => 'Praat met sales',
                ],
                'plans' => [
                    [
                        'slug' => 'creator',
                        'eyebrow' => 'Solo operatie',
                        'audience' => 'Voor solo creators en marketeers',
                        'features' => [
                            '1 workspace',
                            'Basis AI-optimalisatie',
                            'WordPress-publicatie',
                            'Gestructureerde antwoordblokken',
                            'Contentkalender',
                            'E-mailsupport',
                        ],
                        'cta_label' => 'Start Creator',
                    ],
                    [
                        'slug' => 'growth',
                        'eyebrow' => 'Teamworkflow',
                        'audience' => 'Voor groeiende marketingteams',
                        'badge' => 'Meest gekozen',
                        'features' => [
                            'Multi-locale workflows',
                            'AI visibility tracking',
                            'Contentautomations',
                            'Teamsamenwerking',
                            'Content refresh workflows',
                            'API + WordPress-publicatie',
                            'Geavanceerde optimalisatieworkflows',
                            'Priority support',
                        ],
                        'cta_label' => 'Start Growth',
                    ],
                    [
                        'slug' => 'scale',
                        'eyebrow' => 'Operationele schaal',
                        'audience' => 'Voor multi-brand en multi-locale operations',
                        'features' => [
                            'Multi-brand workspaces',
                            'Geavanceerde lokalisatie',
                            'Approval workflows',
                            'Chained content strategy',
                            'AI visibility insights',
                            'Content lifecycle management',
                            'Geavanceerde automations',
                            'Audit logs',
                            'Dedicated onboarding',
                        ],
                        'cta_label' => 'Start Scale',
                    ],
                ],
                'enterprise' => [
                    'badge' => 'Enterprise',
                    'price_label' => 'Prijs op aanvraag',
                    'audience' => 'Maatwerk voor enterprise workflows en governance',
                    'features' => [
                        'Maatwerk creditvolume',
                        'SSO en governance',
                        'Custom AI-workflows',
                        'SLA-support',
                        'Dedicated infrastructuur',
                        'Strategische onboarding',
                        'Custom integraties',
                        'Geavanceerde permissies',
                        'Enterprise support',
                    ],
                    'cta_label' => 'Praat met sales',
                ],
                'comparison' => [
                    'title' => 'Meer dan AI writing',
                    'subtitle' => 'PublishLayer helpt teams de volledige content lifecycle te beheren: van planning tot publicatie en AI discoverability.',
                    'left_label' => 'PublishLayer',
                    'right_label' => 'Traditionele AI writers',
                    'rows' => [
                        ['label' => 'AI writing', 'publishlayer' => true, 'alternative' => true],
                        ['label' => 'Multi-locale publicatie', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'Contentautomations', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'AI visibility tracking', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'Chained content strategy', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'Gestructureerde antwoordblokken', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'CMS-publicatieworkflows', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'Editorial collaboration', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'Content lifecycle management', 'publishlayer' => true, 'alternative' => false],
                        ['label' => 'Multi-brand operations', 'publishlayer' => true, 'alternative' => false],
                    ],
                ],
                'credits' => [
                    'title' => 'Flexibele AI-credits',
                    'body' => 'Credits worden verbruikt door generatie, vertalingen, refreshes, antwoordblokken, research en AI visibility workflows.',
                    'note' => 'Een standaard SEO-artikel gebruikt meestal 10 tot 14 credits, afhankelijk van contentdiepte, research en optimalisatieworkflows.',
                    'points' => [
                        'Subscription plans ontgrendelen features en bevatten maandelijkse credits.',
                        'Ongebruikte subscription credits rollen 3 maanden door.',
                        'Verbruik gebruikt altijd eerst subscription credits en daarna purchased credits.',
                    ],
                ],
                'credit_packs' => [
                    'title' => 'Schaal gebruik wanneer nodig',
                    'subtitle' => 'Voeg op elk moment flexibele credit packs toe zonder je plan te upgraden.',
                    'cards' => [
                        ['key' => 'pack_100', 'description' => 'Korte top-up voor extra productie.', 'badge' => 'Flexibel'],
                        ['key' => 'pack_500', 'description' => 'Ideaal voor campagnes en refresh-cycli.', 'badge' => 'Beste waarde'],
                        ['key' => 'pack_1000', 'description' => 'Voor grotere publicatievolumes over teams heen.', 'badge' => 'Hoog volume'],
                    ],
                    'footer_note' => 'Purchased credit packs blijven 12 maanden geldig en worden gedeeld binnen het workspace-team.',
                    'custom_label' => 'Custom enterprise packs beschikbaar',
                ],
                'team_workflow' => [
                    'title' => 'Gebouwd voor teams, workflows en schaal',
                    'subtitle' => 'Coördineer planning, approvals, lokalisatie, optimalisatie en publicatie zonder losse tools aan elkaar te koppelen.',
                    'points' => [
                        'Editorial collaboration met gestructureerde overdrachten',
                        'Approval- en governance-lagen voor productieteams',
                        'Multi-locale delivery vanuit één operationeel systeem',
                        'Publishing orchestration over CMS- en API-workflows',
                    ],
                ],
                'roi' => [
                    'title' => 'Vervang gefragmenteerde contentworkflows',
                    'items' => [
                        'Verlaag operationele overhead',
                        'Centraliseer publicatie',
                        'Automatiseer lokalisatie',
                        'Verbeter AI discoverability',
                        'Schaal content operations',
                        'Verwijder losse tooling',
                    ],
                ],
                'faq' => [
                    ['question' => 'Is PublishLayer alleen een AI writer?', 'answer' => 'Nee. PublishLayer is een content operations platform voor planning, generatie, optimalisatie, lokalisatie, governance en publishing orchestration.'],
                    ['question' => 'Hoe werken credits?', 'answer' => 'Credits ondersteunen AI-assisted workflows zoals generatie, antwoordblokken, vertalingen, refreshes, research en AI visibility scans.'],
                    ['question' => 'Kan ik extra credits kopen?', 'answer' => 'Ja. Credit packs kun je los aanschaffen zonder van plan te wisselen.'],
                    ['question' => 'Kunnen meerdere teamleden samenwerken?', 'answer' => 'Ja. Growth, Scale en Enterprise zijn ontworpen voor gedeelde workflows, approvals en teamsamenwerking.'],
                    ['question' => 'Kan ik direct naar WordPress publiceren?', 'answer' => 'Ja. PublishLayer ondersteunt WordPress-publicatieworkflows en hogere plannen voegen API-gestuurde delivery toe.'],
                    ['question' => 'Verlopen ongebruikte credits?', 'answer' => 'Included subscription credits rollen 3 maanden door. Purchased credit packs blijven 12 maanden geldig.'],
                    ['question' => 'Worden credit packs gedeeld over teams?', 'answer' => 'Purchased credits worden gedeeld binnen het workspace-team en pas gebruikt nadat included subscription credits zijn verbruikt.'],
                ],
                'final_cta' => [
                    'title' => 'Breng content operations onder in één schaalbaar systeem',
                    'body' => 'Run planning, AI-assisted productie, lokalisatie, optimalisatie en publicatie vanuit één operationeel platform.',
                    'primary_label' => 'Kies je plan',
                    'secondary_label' => 'Praat met sales',
                ],
            ],
        ];
    }
}
