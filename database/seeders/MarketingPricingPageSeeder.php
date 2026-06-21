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
        $platformSlugs = ['platform_250', 'platform_500', 'platform_1000', 'platform_2000', 'enterprise_custom'];
        $legacySlugs = ['creator', 'growth', 'scale', 'enterprise', 'starter', 'pro', 'agency'];

        $existingIds = Plan::query()
            ->whereIn('slug', array_merge($platformSlugs, $legacySlugs))
            ->pluck('id', 'slug');

        $plans = [
            [
                'id' => (string) ($existingIds['platform_250'] ?? Str::uuid()),
                'slug' => 'platform_250',
                'internal_code' => 'platform_250',
                'key' => 'platform_250',
                'name' => 'Argusly Platform',
                'description_short' => 'One platform subscription with 250 monthly credits, one included site, and core marketing operations.',
                'interval' => 'month',
                'price_monthly_cents' => 9900,
                'price_yearly_cents' => 99000,
                'monthly_price_cents' => 9900,
                'price_cents' => 9900,
                'currency' => 'EUR',
                'vat_included' => true,
                'included_credits' => 250,
                'included_credits_per_interval' => 250,
                'article_estimate_min' => null,
                'article_estimate_max' => null,
                'credit_rollover_policy' => 'limited',
                'credit_expiry_days' => 90,
                'credit_rollover_monthly_cycles' => 3,
                'workspace_limit' => 1,
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
                'billing_provider_plan_key' => 'platform_250',
                'is_featured' => false,
                'is_popular' => false,
                'sort_order' => 1,
                'badge' => null,
                'cta_label' => 'Start with 250 credits',
                'cta_href' => null,
                'limits' => [
                    'workspaces' => 1,
                    'sites' => 1,
                    'users' => 5,
                    'extra_site_price_cents' => 2900,
                    'extra_user_price_cents' => 900,
                    'languages_limit' => -1,
                ],
            ],
            [
                'id' => (string) ($existingIds['platform_500'] ?? Str::uuid()),
                'slug' => 'platform_500',
                'internal_code' => 'platform_500',
                'key' => 'platform_500',
                'name' => 'Argusly Platform',
                'description_short' => 'One platform subscription with 500 monthly credits, one included site, and room for recurring execution.',
                'interval' => 'month',
                'price_monthly_cents' => 14900,
                'price_yearly_cents' => 149000,
                'monthly_price_cents' => 14900,
                'price_cents' => 14900,
                'currency' => 'EUR',
                'vat_included' => true,
                'included_credits' => 500,
                'included_credits_per_interval' => 500,
                'article_estimate_min' => null,
                'article_estimate_max' => null,
                'credit_rollover_policy' => 'limited',
                'credit_expiry_days' => 90,
                'credit_rollover_monthly_cycles' => 3,
                'workspace_limit' => 1,
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
                'billing_provider_plan_key' => 'platform_500',
                'is_featured' => true,
                'is_popular' => true,
                'sort_order' => 2,
                'badge' => 'Most popular',
                'cta_label' => 'Start with 500 credits',
                'cta_href' => null,
                'limits' => [
                    'workspaces' => 1,
                    'sites' => 1,
                    'users' => 5,
                    'extra_site_price_cents' => 2900,
                    'extra_user_price_cents' => 900,
                    'languages_limit' => -1,
                ],
            ],
            [
                'id' => (string) ($existingIds['platform_1000'] ?? Str::uuid()),
                'slug' => 'platform_1000',
                'internal_code' => 'platform_1000',
                'key' => 'platform_1000',
                'name' => 'Argusly Platform',
                'description_short' => 'One platform subscription with 1,000 monthly credits for heavier marketing operations.',
                'interval' => 'month',
                'price_monthly_cents' => 24900,
                'price_yearly_cents' => 249000,
                'monthly_price_cents' => 24900,
                'price_cents' => 24900,
                'currency' => 'EUR',
                'vat_included' => true,
                'included_credits' => 1000,
                'included_credits_per_interval' => 1000,
                'article_estimate_min' => null,
                'article_estimate_max' => null,
                'credit_rollover_policy' => 'limited',
                'credit_expiry_days' => 90,
                'credit_rollover_monthly_cycles' => 3,
                'workspace_limit' => 1,
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
                'billing_provider_plan_key' => 'platform_1000',
                'is_featured' => false,
                'is_popular' => false,
                'sort_order' => 3,
                'badge' => null,
                'cta_label' => 'Start with 1,000 credits',
                'cta_href' => null,
                'limits' => [
                    'workspaces' => 1,
                    'sites' => 1,
                    'users' => 5,
                    'extra_site_price_cents' => 2900,
                    'extra_user_price_cents' => 900,
                    'languages_limit' => -1,
                ],
            ],
            [
                'id' => (string) ($existingIds['platform_2000'] ?? Str::uuid()),
                'slug' => 'platform_2000',
                'internal_code' => 'platform_2000',
                'key' => 'platform_2000',
                'name' => 'Argusly Platform',
                'description_short' => 'One platform subscription with 2,000 monthly credits for high-volume execution.',
                'interval' => 'month',
                'price_monthly_cents' => 39900,
                'price_yearly_cents' => 399000,
                'monthly_price_cents' => 39900,
                'price_cents' => 39900,
                'currency' => 'EUR',
                'vat_included' => true,
                'included_credits' => 2000,
                'included_credits_per_interval' => 2000,
                'article_estimate_min' => null,
                'article_estimate_max' => null,
                'credit_rollover_policy' => 'limited',
                'credit_expiry_days' => 90,
                'credit_rollover_monthly_cycles' => 3,
                'workspace_limit' => 1,
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
                'billing_provider_plan_key' => 'platform_2000',
                'is_featured' => false,
                'is_popular' => false,
                'sort_order' => 4,
                'badge' => null,
                'cta_label' => 'Start with 2,000 credits',
                'cta_href' => null,
                'limits' => [
                    'workspaces' => 1,
                    'sites' => 1,
                    'users' => 5,
                    'extra_site_price_cents' => 2900,
                    'extra_user_price_cents' => 900,
                    'languages_limit' => -1,
                ],
            ],
            [
                'id' => (string) ($existingIds['enterprise_custom'] ?? $existingIds['enterprise'] ?? Str::uuid()),
                'slug' => 'enterprise_custom',
                'internal_code' => 'enterprise_custom',
                'key' => 'enterprise_custom',
                'name' => 'Enterprise',
                'description_short' => 'Custom pricing for many sites, agencies, SSO, audit logs, SLA, onboarding, and custom credit volumes.',
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
                'billing_provider_plan_key' => 'enterprise_custom',
                'is_featured' => false,
                'is_popular' => false,
                'sort_order' => 5,
                'badge' => 'Custom',
                'cta_label' => 'Talk to sales',
                'cta_href' => '/contact?subject=enterprise-pricing#contact-form',
                'limits' => [
                    'workspaces' => -1,
                    'sites' => -1,
                    'users' => -1,
                    'extra_site_price_cents' => 2900,
                    'languages_limit' => -1,
                ],
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
            ->whereIn('slug', $platformSlugs)
            ->update([
                'is_featured' => false,
                'is_popular' => false,
                'badge' => DB::raw("CASE WHEN badge = 'Most popular' THEN NULL ELSE badge END"),
            ]);

        Plan::query()
            ->where('slug', 'platform_500')
            ->update([
                'is_featured' => true,
                'is_popular' => true,
                'badge' => 'Most popular',
            ]);

        Plan::query()
            ->whereIn('slug', $legacySlugs)
            ->update([
                'is_active' => false,
                'is_public' => false,
                'is_featured' => false,
                'is_popular' => false,
                'badge' => null,
            ]);

        $featureRows = [
            'platform_250' => [
                ['feature_key' => 'ai_visibility_tracking', 'label' => 'AI visibility', 'feature_group' => 'AI visibility', 'is_highlight' => true, 'sort_order' => 10],
                ['feature_key' => 'opportunity_discovery', 'label' => 'Opportunity discovery', 'feature_group' => 'Growth', 'is_highlight' => true, 'sort_order' => 20],
                ['feature_key' => 'wordpress_connector', 'label' => 'WordPress publishing', 'feature_group' => 'Publishing', 'is_highlight' => true, 'sort_order' => 30],
                ['feature_key' => 'content_calendar', 'label' => 'Content calendar', 'feature_group' => 'Planning', 'is_highlight' => true, 'sort_order' => 40],
                ['feature_key' => 'content_generation', 'label' => 'Content generation', 'feature_group' => 'Execution', 'is_highlight' => true, 'sort_order' => 50],
                ['feature_key' => 'content_automations', 'label' => 'Automations', 'feature_group' => 'Automation', 'is_highlight' => true, 'sort_order' => 60],
                ['feature_key' => 'reporting', 'label' => 'Reporting', 'feature_group' => 'Reporting', 'is_highlight' => true, 'sort_order' => 70],
                ['feature_key' => 'draft_compare_enabled', 'label' => 'Draft Compare enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 170, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'draft_compare_max_models', 'label' => 'Draft Compare max models', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 171, 'value_type' => 'int', 'value_int' => 3],
                ['feature_key' => 'draft_compare_hybrid_enabled', 'label' => 'Draft Compare hybrid enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 172, 'value_type' => 'bool', 'value_bool' => false],
                ['feature_key' => 'draft_compare_scoring_enabled', 'label' => 'Draft Compare scoring enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 173, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'draft_compare_premium_models_enabled', 'label' => 'Draft Compare premium models enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 174, 'value_type' => 'bool', 'value_bool' => false],
                ['feature_key' => 'translation_enabled', 'label' => 'Translation enabled', 'feature_group' => 'Localization', 'is_highlight' => false, 'sort_order' => 150, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'automation_enabled', 'label' => 'Automation enabled', 'feature_group' => 'Automation', 'is_highlight' => false, 'sort_order' => 160, 'value_type' => 'bool', 'value_bool' => true],
            ],
            'platform_500' => [],
            'platform_1000' => [],
            'platform_2000' => [],
            'enterprise_custom' => [
                ['feature_key' => 'sso_enabled', 'label' => 'SSO and autonomy governance', 'feature_group' => 'Security', 'is_highlight' => true, 'sort_order' => 10, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'custom_ai_workflows', 'label' => 'Custom AI and agentic workflows', 'feature_group' => 'Automation', 'is_highlight' => true, 'sort_order' => 20],
                ['feature_key' => 'sla_support', 'label' => 'SLA support', 'feature_group' => 'Support', 'is_highlight' => true, 'sort_order' => 30],
                ['feature_key' => 'dedicated_infrastructure', 'label' => 'Dedicated rollout options', 'feature_group' => 'Rollout', 'is_highlight' => true, 'sort_order' => 40],
                ['feature_key' => 'custom_integrations', 'label' => 'Custom product extensions', 'feature_group' => 'Integration', 'is_highlight' => true, 'sort_order' => 50],
                ['feature_key' => 'advanced_permissions', 'label' => 'Advanced permissions', 'feature_group' => 'Governance', 'is_highlight' => true, 'sort_order' => 60],
                ['feature_key' => 'draft_compare_enabled', 'label' => 'Draft Compare enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 170, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'draft_compare_max_models', 'label' => 'Draft Compare max models', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 171, 'value_type' => 'int', 'value_int' => 8],
                ['feature_key' => 'draft_compare_hybrid_enabled', 'label' => 'Draft Compare hybrid enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 172, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'draft_compare_scoring_enabled', 'label' => 'Draft Compare scoring enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 173, 'value_type' => 'bool', 'value_bool' => true],
                ['feature_key' => 'draft_compare_premium_models_enabled', 'label' => 'Draft Compare premium models enabled', 'feature_group' => 'AI', 'is_highlight' => false, 'sort_order' => 174, 'value_type' => 'bool', 'value_bool' => true],
            ],
        ];

        foreach (['platform_500', 'platform_1000', 'platform_2000'] as $slug) {
            $featureRows[$slug] = $featureRows['platform_250'];
        }

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
        Plan::query()
            ->whereIn('slug', ['creator', 'growth', 'scale', 'enterprise', 'starter', 'pro', 'agency'])
            ->update([
                'is_active' => false,
                'is_public' => false,
                'is_featured' => false,
                'is_popular' => false,
                'badge' => null,
            ]);
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
                    'eyebrow' => 'One platform. Usage-based scaling.',
                    'headline' => 'Pricing that scales with your marketing operation',
                    'subheadline' => 'Start with one platform, choose your monthly credits and add sites when needed.',
                    'supporting_text' => 'Credits power the work Argusly performs for your marketing operation, including AI visibility analysis, opportunity discovery, content generation, refresh workflows and publishing automation.',
                    'primary_cta_label' => 'Choose monthly credits',
                    'secondary_cta_label' => 'Talk to sales',
                ],
                'plans' => [
                    [
                        'slug' => 'platform_250',
                        'eyebrow' => '250 credits/month',
                        'audience' => 'Start with platform access, one site, five users and enough credits for focused visibility and execution work.',
                        'features' => ['1 site included', '5 users included', 'AI visibility', 'Opportunity discovery', 'Content generation', 'Content calendar', 'Publishing workflows', 'Automations', 'Reporting'],
                        'cta_label' => 'Start with 250 credits',
                    ],
                    [
                        'slug' => 'platform_500',
                        'eyebrow' => '500 credits/month',
                        'audience' => 'A balanced monthly volume for teams running recurring scans, opportunity work and publishing workflows.',
                        'badge' => 'Most popular',
                        'features' => ['1 site included', '5 users included', 'AI visibility', 'Opportunity discovery', 'Content generation', 'Content calendar', 'Publishing workflows', 'Automations', 'Reporting'],
                        'cta_label' => 'Start with 500 credits',
                    ],
                    [
                        'slug' => 'platform_1000',
                        'eyebrow' => '1,000 credits/month',
                        'audience' => 'For teams scaling opportunity detection, content refreshes, generation and publishing automation.',
                        'features' => ['1 site included', '5 users included', 'AI visibility', 'Opportunity discovery', 'Content generation', 'Content calendar', 'Publishing workflows', 'Automations', 'Reporting'],
                        'cta_label' => 'Start with 1,000 credits',
                    ],
                    [
                        'slug' => 'platform_2000',
                        'eyebrow' => '2,000 credits/month',
                        'audience' => 'For high-volume marketing operations that need more monthly execution capacity.',
                        'features' => ['1 site included', '5 users included', 'AI visibility', 'Opportunity discovery', 'Content generation', 'Content calendar', 'Publishing workflows', 'Automations', 'Reporting'],
                        'cta_label' => 'Start with 2,000 credits',
                    ],
                ],
                'enterprise' => [
                    'badge' => 'Enterprise',
                    'price_label' => 'Custom pricing',
                    'audience' => 'Custom pricing for many sites, agencies, SSO, audit logs, SLA, dedicated onboarding and custom credit volumes.',
                    'body' => 'Enterprise is for agency and high-volume operations that need custom site structures, governance, onboarding and credit capacity.',
                    'features' => [
                        'Custom growth intelligence capacity',
                        'SSO and autonomy governance',
                        'Custom AI and agentic workflows',
                        'SLA support',
                        'Dedicated rollout options',
                        'Strategic onboarding',
                        'Custom product extensions',
                        'Advanced permissions',
                        'Enterprise support',
                    ],
                    'cta_label' => 'Plan enterprise rollout',
                ],
                'comparison' => [
                    'title' => 'More than AI writing',
                    'subtitle' => 'Argusly helps teams manage the full content lifecycle from planning to publishing and AI discoverability.',
                    'left_label' => 'Argusly',
                    'right_label' => 'Traditional AI writers',
                    'rows' => [
                        ['label' => 'AI writing', 'argusly' => true, 'alternative' => true],
                        ['label' => 'Multi-locale publishing', 'argusly' => true, 'alternative' => false],
                        ['label' => 'Content automations', 'argusly' => true, 'alternative' => false],
                        ['label' => 'AI visibility tracking', 'argusly' => true, 'alternative' => false],
                        ['label' => 'Chained content strategy', 'argusly' => true, 'alternative' => false],
                        ['label' => 'Structured answer blocks', 'argusly' => true, 'alternative' => false],
                        ['label' => 'CMS, API and LinkedIn publishing workflows', 'argusly' => true, 'alternative' => false],
                        ['label' => 'Editorial collaboration', 'argusly' => true, 'alternative' => false],
                        ['label' => 'Content lifecycle management', 'argusly' => true, 'alternative' => false],
                        ['label' => 'Multi-brand operations', 'argusly' => true, 'alternative' => false],
                    ],
                ],
                'credits' => [
                    'title' => 'Credits power the work Argusly performs',
                    'body' => 'Credits cover AI visibility scans, opportunity detection, content generation, content refreshes, publishing workflows and automation runs.',
                    'note' => 'Monthly credits renew with your subscription. Purchased credit packs are separate top-ups for temporary peaks.',
                    'points' => [
                        'Run AI visibility scans, competitive analysis, refreshes, answer blocks, and distribution from one workflow.',
                        'Scale visibility work without rebuilding your operating model.',
                        'Add capacity when a market opportunity needs faster execution.',
                    ],
                ],
                'addons' => [
                    'title' => 'Add sites when your operation grows',
                    'items' => [
                        ['label' => 'Extra site', 'price' => '€29/month', 'description' => 'Connect another site or domain to the same platform subscription.'],
                        ['label' => 'Extra credits', 'price' => 'Credit packs', 'description' => 'Buy temporary top-ups without changing your monthly credit tier.'],
                        ['label' => 'Enterprise', 'price' => 'Custom', 'description' => 'For agencies, many sites, SSO, audit logs, SLA and custom credit volumes.'],
                    ],
                ],
                'credit_packs' => [
                    'title' => 'Credit packs for temporary peaks',
                    'subtitle' => 'Credit packs are separate from monthly credits and stay valid for 12 months.',
                    'cards' => [
                        ['key' => 'pack_100', 'description' => 'Short-term top-up for extra production.', 'badge' => 'Flexible'],
                        ['key' => 'pack_500', 'description' => 'Best for seasonal campaigns and refresh cycles.', 'badge' => 'Best value'],
                        ['key' => 'pack_1000', 'description' => 'For heavier publishing operations across teams.', 'badge' => 'High volume'],
                    ],
                    'footer_note' => 'Purchased credits are used for temporary peaks and remain separate from monthly subscription credits.',
                    'custom_label' => 'Custom enterprise credit volumes available',
                ],
                'team_workflow' => [
                    'title' => 'Built for teams, workflows and scale',
                    'subtitle' => 'Coordinate planning, approvals, localization, optimization and publishing without stitching together disconnected tools.',
                    'points' => [
                        'Editorial collaboration with structured handoffs',
                        'Approval and governance layers for production teams',
                        'Multi-locale delivery from one operational system',
                        'Publishing orchestration across CMS, API and LinkedIn workflows',
                    ],
                ],
                'roi' => [
                    'title' => 'Focus spend on growth impact',
                    'items' => [
                        'Find visibility gaps earlier',
                        'Prioritize competitive response',
                        'Refresh pages with measurable upside',
                        'Improve AI answer coverage',
                        'Scale governed execution',
                        'Keep CMS and channel control',
                    ],
                ],
                'faq' => [
                    ['question' => 'What is a credit?', 'answer' => 'A credit represents work Argusly performs, such as AI visibility analysis, opportunity discovery, content generation, refresh workflows and publishing automation.'],
                    ['question' => 'What happens when I run out of credits?', 'answer' => 'You can buy a credit pack for temporary peaks or move to a higher monthly credit tier.'],
                    ['question' => 'Can I add more sites?', 'answer' => 'Yes. Every platform subscription includes one site. Extra sites cost €29 per month per site.'],
                    ['question' => 'Are workspaces still available?', 'answer' => 'Yes. Workspaces remain available as an internal organization concept, but they are no longer the primary pricing object.'],
                    ['question' => 'Do unused monthly credits roll over?', 'answer' => 'Monthly credits follow the subscription rollover policy shown in-app. Purchased credit packs remain separate.'],
                    ['question' => 'How long are purchased credit packs valid?', 'answer' => 'Purchased credit packs are valid for 12 months.'],
                    ['question' => 'Is there agency pricing?', 'answer' => 'Yes. Enterprise pricing is available for agencies, many sites, SSO, audit logs, SLA and custom credit volumes.'],
                ],
                'final_cta' => [
                    'title' => 'See which growth opportunities your market is already exposing',
                    'body' => 'Start with AI visibility, competitive gaps, and high-impact content opportunities, then choose the plan that matches your execution pace.',
                    'primary_label' => 'Compare growth outcomes',
                    'secondary_label' => 'Plan an AI Visibility Scan',
                ],
            ],
            'nl' => [
                'hero' => [
                    'eyebrow' => 'Eén platform. Usage-based scaling.',
                    'headline' => 'Pricing die meegroeit met je marketingoperatie',
                    'subheadline' => 'Start met één platform, kies je maandelijkse credits en voeg sites toe wanneer dat nodig is.',
                    'supporting_text' => 'Credits bepalen hoeveel werk Argusly voor je marketingoperatie uitvoert, zoals AI visibility analyses, kansdetectie, contentgeneratie, refresh workflows en publicatieautomatisering.',
                    'primary_cta_label' => 'Kies maandelijkse credits',
                    'secondary_cta_label' => 'Neem contact op',
                ],
                'plans' => [
                    [
                        'slug' => 'platform_250',
                        'eyebrow' => '250 credits/maand',
                        'audience' => 'Start met platform access, één site, vijf gebruikers en credits voor gerichte visibility- en executieworkflows.',
                        'features' => ['1 site inbegrepen', '5 gebruikers inbegrepen', 'AI visibility', 'Kansdetectie', 'Contentgeneratie', 'Contentkalender', 'Publishing workflows', 'Automations', 'Reporting'],
                        'cta_label' => 'Start met 250 credits',
                    ],
                    [
                        'slug' => 'platform_500',
                        'eyebrow' => '500 credits/maand',
                        'audience' => 'Een gebalanceerd maandvolume voor teams die terugkerende scans, kansen en publicatieworkflows draaien.',
                        'badge' => 'Meest gekozen',
                        'features' => ['1 site inbegrepen', '5 gebruikers inbegrepen', 'AI visibility', 'Kansdetectie', 'Contentgeneratie', 'Contentkalender', 'Publishing workflows', 'Automations', 'Reporting'],
                        'cta_label' => 'Start met 500 credits',
                    ],
                    [
                        'slug' => 'platform_1000',
                        'eyebrow' => '1.000 credits/maand',
                        'audience' => 'Voor teams die kansdetectie, content refreshes, generatie en publicatieautomatisering opschalen.',
                        'features' => ['1 site inbegrepen', '5 gebruikers inbegrepen', 'AI visibility', 'Kansdetectie', 'Contentgeneratie', 'Contentkalender', 'Publishing workflows', 'Automations', 'Reporting'],
                        'cta_label' => 'Start met 1.000 credits',
                    ],
                    [
                        'slug' => 'platform_2000',
                        'eyebrow' => '2.000 credits/maand',
                        'audience' => 'Voor marketingoperaties met hoog volume die meer maandelijkse executiecapaciteit nodig hebben.',
                        'features' => ['1 site inbegrepen', '5 gebruikers inbegrepen', 'AI visibility', 'Kansdetectie', 'Contentgeneratie', 'Contentkalender', 'Publishing workflows', 'Automations', 'Reporting'],
                        'cta_label' => 'Start met 2.000 credits',
                    ],
                ],
                'enterprise' => [
                    'badge' => 'Enterprise',
                    'price_label' => 'Prijs op aanvraag',
                    'audience' => 'Custom pricing voor veel sites, agencies, SSO, audit logs, SLA, dedicated onboarding en maatwerk creditvolumes.',
                    'body' => 'Enterprise is voor agencies en high-volume operations die maatwerk nodig hebben rond sites, governance, onboarding en creditcapaciteit.',
                    'features' => [
                        'Maatwerk growth intelligence capacity',
                        'SSO en autonomiegovernance',
                        'Custom AI- en agentic workflows',
                        'SLA-support',
                        'Dedicated rollout-opties',
                        'Strategische onboarding',
                        'Custom productuitbreidingen',
                        'Geavanceerde permissies',
                        'Enterprise support',
                    ],
                    'cta_label' => 'Plan enterprise rollout',
                ],
                'comparison' => [
                    'title' => 'Meer dan AI writing',
                    'subtitle' => 'Argusly helpt teams de volledige content lifecycle te beheren: van planning tot publicatie en AI discoverability.',
                    'left_label' => 'Argusly',
                    'right_label' => 'Traditionele AI writers',
                    'rows' => [
                        ['label' => 'AI writing', 'argusly' => true, 'alternative' => true],
                        ['label' => 'Multi-locale publicatie', 'argusly' => true, 'alternative' => false],
                        ['label' => 'Contentautomations', 'argusly' => true, 'alternative' => false],
                        ['label' => 'AI visibility tracking', 'argusly' => true, 'alternative' => false],
                        ['label' => 'Chained content strategy', 'argusly' => true, 'alternative' => false],
                        ['label' => 'Gestructureerde antwoordblokken', 'argusly' => true, 'alternative' => false],
                        ['label' => 'CMS-, API- en LinkedIn-publicatieworkflows', 'argusly' => true, 'alternative' => false],
                        ['label' => 'Editorial collaboration', 'argusly' => true, 'alternative' => false],
                        ['label' => 'Content lifecycle management', 'argusly' => true, 'alternative' => false],
                        ['label' => 'Multi-brand operations', 'argusly' => true, 'alternative' => false],
                    ],
                ],
                'credits' => [
                    'title' => 'Credits bepalen hoeveel werk Argusly uitvoert',
                    'body' => 'Credits worden gebruikt voor AI visibility scans, kansdetectie, contentgeneratie, content refreshes, publishing workflows en automation runs.',
                    'note' => 'Maandelijkse credits vernieuwen met je abonnement. Gekochte credit packs zijn losse top-ups voor tijdelijke pieken.',
                    'points' => [
                        'Run AI visibility scans, competitive analysis, refreshes, answer blocks en distributie vanuit één workflow.',
                        'Schaal visibility work zonder je operating model opnieuw te bouwen.',
                        'Voeg capaciteit toe wanneer een marktkans snellere executie vraagt.',
                    ],
                ],
                'addons' => [
                    'title' => 'Voeg sites toe wanneer je operatie groeit',
                    'items' => [
                        ['label' => 'Extra site', 'price' => '€29/maand', 'description' => 'Koppel nog een site of domein aan hetzelfde platformabonnement.'],
                        ['label' => 'Extra credits', 'price' => 'Credit packs', 'description' => 'Koop tijdelijke top-ups zonder je maandelijkse credit tier te wijzigen.'],
                        ['label' => 'Enterprise', 'price' => 'Maatwerk', 'description' => 'Voor agencies, veel sites, SSO, audit logs, SLA en custom creditvolumes.'],
                    ],
                ],
                'credit_packs' => [
                    'title' => 'Credit packs voor tijdelijke pieken',
                    'subtitle' => 'Credit packs staan los van maandelijkse credits en blijven 12 maanden geldig.',
                    'cards' => [
                        ['key' => 'pack_100', 'description' => 'Korte top-up voor extra productie.', 'badge' => 'Flexibel'],
                        ['key' => 'pack_500', 'description' => 'Ideaal voor campagnes en refresh-cycli.', 'badge' => 'Beste waarde'],
                        ['key' => 'pack_1000', 'description' => 'Voor grotere publicatievolumes over teams heen.', 'badge' => 'Hoog volume'],
                    ],
                    'footer_note' => 'Gekochte credits gebruik je voor tijdelijke pieken en blijven gescheiden van maandelijkse abonnementscredits.',
                    'custom_label' => 'Custom enterprise creditvolumes beschikbaar',
                ],
                'team_workflow' => [
                    'title' => 'Gebouwd voor teams, workflows en schaal',
                    'subtitle' => 'Coördineer planning, approvals, lokalisatie, optimalisatie en publicatie zonder losse tools aan elkaar te koppelen.',
                    'points' => [
                        'Editorial collaboration met gestructureerde overdrachten',
                        'Approval- en governance-lagen voor productieteams',
                        'Multi-locale delivery vanuit één operationeel systeem',
                        'Publishing orchestration over CMS-, API- en LinkedIn-workflows',
                    ],
                ],
                'roi' => [
                    'title' => 'Richt budget op groei-impact',
                    'items' => [
                        'Vind visibility gaps eerder',
                        'Prioriteer competitive response',
                        'Refresh pagina’s met meetbare upside',
                        'Verbeter AI answer coverage',
                        'Schaal governed execution',
                        'Behoud CMS- en kanaalcontrole',
                    ],
                ],
                'faq' => [
                    ['question' => 'Wat is een credit?', 'answer' => 'Een credit staat voor werk dat Argusly uitvoert, zoals AI visibility analyse, kansdetectie, contentgeneratie, refresh workflows en publicatieautomatisering.'],
                    ['question' => 'Wat gebeurt er als mijn credits op zijn?', 'answer' => 'Je kunt een credit pack kopen voor tijdelijke pieken of overstappen naar een hogere maandelijkse credit tier.'],
                    ['question' => 'Kan ik meer sites toevoegen?', 'answer' => 'Ja. Elk platformabonnement bevat één site. Extra sites kosten €29 per maand per site.'],
                    ['question' => 'Zijn workspaces nog beschikbaar?', 'answer' => 'Ja. Workspaces blijven beschikbaar als intern organisatieconcept, maar zijn niet langer het primaire pricing object.'],
                    ['question' => 'Rollen ongebruikte maandelijkse credits door?', 'answer' => 'Maandelijkse credits volgen de rollover policy die in de app wordt getoond. Gekochte credit packs blijven apart.'],
                    ['question' => 'Hoe lang zijn gekochte credit packs geldig?', 'answer' => 'Gekochte credit packs zijn 12 maanden geldig.'],
                    ['question' => 'Is er agency pricing?', 'answer' => 'Ja. Enterprise pricing is beschikbaar voor agencies, veel sites, SSO, audit logs, SLA en custom creditvolumes.'],
                ],
                'final_cta' => [
                    'title' => 'Zie welke groeikansen je markt nu al blootlegt',
                    'body' => 'Start met AI visibility, competitive gaps en high-impact content opportunities, en kies daarna het plan dat past bij je executietempo.',
                    'primary_label' => 'Vergelijk growth outcomes',
                    'secondary_label' => 'Vraag een AI Visibility Scan aan',
                ],
            ],
        ];
    }
}
