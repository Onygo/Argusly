<?php

namespace App\Http\Controllers;

use App\Models\CreditPack;
use App\Models\Plan;
use App\Services\MarketingPricingService;
use App\Services\SiteSettingsService;
use App\Support\LocalizedMarketingUrl;
use App\Support\OnboardingFee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class LandingController extends Controller
{
    public function index(Request $request): View
    {
        $locale = (string) app()->getLocale();

        if ((bool) config('argusly.launch.soft_launch_mode', false)) {
            return view('public.soft-launch', [
                'metaTitle' => 'Argusly | Private rollout',
                'metaDescription' => 'Argusly is onboarding a limited number of early partners.',
                'canonicalUrl' => LocalizedMarketingUrl::route('landing', [], $locale),
                'hreflangUrls' => LocalizedMarketingUrl::hreflangsForRoute('landing'),
            ]);
        }

        return view('public.landing', [
            'plans' => $this->activePlans(),
            'creditPacks' => $this->activeCreditPacks(),
            'initialSection' => (string) $request->query('section', ''),
            'metaTitle' => (string) __('public.landing.meta_title'),
            'metaDescription' => (string) __('public.landing.meta_description'),
            'canonicalUrl' => LocalizedMarketingUrl::route('landing', [], $locale),
            'hreflangUrls' => LocalizedMarketingUrl::hreflangsForRoute('landing'),
        ]);
    }

    public function pricing(Request $request, MarketingPricingService $pricing): View|RedirectResponse
    {
        $locale = (string) app()->getLocale();

        if ((bool) config('argusly.launch.soft_launch_mode', false) || ! (bool) config('argusly.launch.public_pricing_enabled', true)) {
            return redirect()->to(LocalizedMarketingUrl::route('public.early-access.show', ['intent' => 'early_access'], $locale));
        }

        return view('public.pricing', [
            'plans' => $pricing->fixedPlans($locale),
            'enterprisePlan' => $pricing->enterprisePlan($locale),
            'creditPacks' => $pricing->creditPacks($locale),
            'pageContent' => $pricing->content($locale),
            'metaTitle' => (string) __('public.landing.pricing_meta_title'),
            'metaDescription' => (string) __('public.landing.pricing_meta_description'),
            'canonicalUrl' => LocalizedMarketingUrl::route('pricing', [], $locale),
            'hreflangUrls' => LocalizedMarketingUrl::hreflangsForRoute('pricing'),
        ]);
    }

    /**
     * Get pricing page content from admin settings with translation fallbacks.
     *
     * @return array<string, mixed>
     */
    private function pricingPageContent(): array
    {
        $content = Cache::remember('public.pricing.page_content', now()->addMinutes(5), function (): array {
            $settings = app(SiteSettingsService::class);
            return $settings->get('pricing_page_content', []);
        });

        return [
            'hero_badge' => $content['hero_badge'] ?? __('public.landing.pricing_badge'),
            'hero_title' => $content['hero_title'] ?? __('public.landing.pricing_title'),
            'hero_subline' => $content['hero_subline'] ?? __('public.landing.pricing_subline'),
            'hero_text_1' => $content['hero_text_1'] ?? __('public.landing.pricing_text_1'),
            'hero_text_2' => $content['hero_text_2'] ?? __('public.landing.pricing_text_2'),
            'hero_note' => $content['hero_note'] ?? __('public.landing.credits_usage_note'),
            'credit_top_up_helper' => $content['credit_top_up_helper'] ?? __('public.landing.pricing_credit_top_up_helper'),
            'monthly_no_setup_text' => $content['monthly_no_setup_text'] ?? __('public.landing.pricing_monthly_no_setup'),
            'includes' => ! empty($content['includes']) ? $content['includes'] : __('public.landing.pricing_includes'),
            'why_title' => $content['why_title'] ?? __('public.landing.why_title'),
            'why_points' => ! empty($content['why_points']) ? $content['why_points'] : trans('public.landing.why_points'),
            'credit_faq_title' => $content['credit_faq_title'] ?? __('public.landing.credit_faq_title'),
            'credit_faq_text' => $content['credit_faq_text'] ?? __('public.landing.credit_faq_text'),
            'credit_examples' => ! empty($content['credit_examples']) ? $content['credit_examples'] : trans('public.landing.credit_examples'),
            'credit_failure_note' => $content['credit_failure_note'] ?? __('public.landing.credit_failure_note'),
            'enterprise_title' => $content['enterprise_title'] ?? __('public.landing.enterprise_title'),
            'enterprise_text' => $content['enterprise_text'] ?? __('public.landing.enterprise_text'),
            'enterprise_points' => ! empty($content['enterprise_points']) ? $content['enterprise_points'] : trans('public.landing.enterprise_points'),
            'bottom_cta_title' => $content['bottom_cta_title'] ?? null,
            'bottom_cta_text' => $content['bottom_cta_text'] ?? null,
            'bottom_cta_button_label' => $content['bottom_cta_button_label'] ?? null,
            'bottom_cta_button_url' => $content['bottom_cta_button_url'] ?? null,
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function activePlans(): Collection
    {
        if (! Schema::hasTable('plans')) {
            return collect();
        }

        /** @var Collection<int,array<string,mixed>> $plans */
        $plans = Cache::remember('public.landing.active_plans', now()->addMinutes(5), function (): Collection {
            $query = Plan::query()
                ->publiclyVisible()
                ->fixedBilling()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->limit(3);
            if (Schema::hasTable('plan_features')) {
                $query->with('features');
            }

            return $query
                ->get()
                ->map(function (Plan $plan): array {
                    $limits = is_array($plan->limits) ? $plan->limits : [];
                    $allFeatures = collect();
                    $featureValues = [];
                    if ($plan->relationLoaded('features')) {
                        $allFeatures = $plan->features
                            ->sortBy(fn ($row) => [(int) ($row->sort_order ?? 999999), (string) ($row->label ?? $row->feature_key)]);

                        $featureValues = $allFeatures
                            ->mapWithKeys(fn ($row) => [(string) $row->feature_key => $row->typedValue()])
                            ->all();
                    }

                    $highlights = $allFeatures
                        ->filter(fn ($row) => (bool) ($row->is_highlight ?? false) === true)
                        ->map(fn ($row) => trim((string) ($row->label ?: str_replace('_', ' ', ucfirst((string) $row->feature_key)))))
                        ->filter()
                        ->take(10)
                        ->values()
                        ->all();

                    $groupedDetails = $allFeatures
                        ->groupBy(fn ($row) => (string) ($row->feature_group ?: 'General'))
                        ->map(function ($rows): array {
                            return $rows
                                ->map(fn ($row) => trim((string) ($row->label ?: str_replace('_', ' ', ucfirst((string) $row->feature_key)))))
                                ->filter()
                                ->values()
                                ->all();
                        })
                        ->filter(fn (array $rows): bool => count($rows) > 0)
                        ->all();

                    $priceMonthlyCents = $plan->price_monthly_cents;
                    if ($priceMonthlyCents === null) {
                        $priceMonthlyCents = $plan->monthly_price_cents > 0 ? (int) $plan->monthly_price_cents : $plan->price_cents;
                    }

                    $includedDrafts = Arr::get($limits, 'included_drafts_per_month');
                    $includedCredits = Arr::get($limits, 'included_credits_per_month', Arr::get($limits, 'included_credits'));
                    $planSlug = (string) ($plan->slug ?: $plan->key);
                    $isStarter = $planSlug === 'starter';
                    $onboarding = $plan->onboardingData();
                    $onboardingFeeCents = max(0, (int) ($onboarding['fee_cents'] ?? 0));
                    $workspaceLimit = $this->normalizePlanLimit(Arr::get($limits, 'workspaces'), 1);
                    $siteLimit = $this->normalizePlanLimit(Arr::get($limits, 'sites'), 1);
                    $userLimit = $this->normalizePlanLimit(Arr::get($limits, 'users', $plan->seat_limit), max(1, (int) $plan->seat_limit));

                    return [
                        'id' => (string) $plan->id,
                        'key' => (string) ($plan->slug ?: $plan->key),
                        'slug' => (string) ($plan->slug ?: $plan->key),
                        'name' => (string) $plan->name,
                        'description' => (string) ($plan->description_short ?: Arr::get($limits, 'description', '')),
                        'price_monthly_cents' => $priceMonthlyCents === null ? null : (int) $priceMonthlyCents,
                        'price_yearly_cents' => $plan->price_yearly_cents === null ? null : (int) $plan->price_yearly_cents,
                        'currency' => (string) ($plan->currency ?: 'EUR'),
                        'vat_included' => (bool) $plan->vat_included,
                        'included_credits' => (int) ($plan->included_credits_per_interval ?: $plan->included_credits ?: 0),
                        'billing_type' => (string) $plan->billing_type,
                        'onboarding' => [
                            'required' => (bool) ($onboarding['required'] ?? false),
                            'label' => trim((string) ($onboarding['label'] ?? '')),
                            'fee_cents' => $onboardingFeeCents,
                            'description' => trim((string) ($onboarding['description'] ?? '')),
                            'display_mode' => (string) ($onboarding['display_mode'] ?? ''),
                            'currency' => (string) ($onboarding['fee_currency'] ?? ($plan->currency ?: 'EUR')),
                            'is_visible_public' => (bool) ($onboarding['is_visible_public'] ?? true),
                            'one_time' => $onboardingFeeCents > 0,
                        ],
                        'limits' => [
                            'workspaces' => $workspaceLimit,
                            'sites' => $siteLimit,
                            'users' => $userLimit,
                            'included_drafts_per_month' => $includedDrafts,
                            'included_credits_per_month' => $includedCredits,
                        ],
                        'feature_values' => [
                            'topics_seed_keywords_limit' => (int) ($featureValues['topics_seed_keywords_limit'] ?? Arr::get($limits, 'topics_seed_keywords_limit', -1)),
                            'articles_per_month_limit' => (int) ($featureValues['articles_per_month_limit'] ?? Arr::get($limits, 'articles_per_month_limit', Arr::get($limits, 'included_drafts_per_month', -1))),
                            'llm_tracking_queries_per_month_limit' => (int) ($featureValues['llm_tracking_queries_per_month_limit'] ?? Arr::get($limits, 'llm_tracking_queries_per_month_limit', -1)),
                            'competitor_slots_limit' => (int) ($featureValues['competitor_slots_limit'] ?? Arr::get($limits, 'competitor_slots_limit', -1)),
                            'seo_audit_crawl_pages_per_month_limit' => (int) ($featureValues['seo_audit_crawl_pages_per_month_limit'] ?? Arr::get($limits, 'seo_audit_crawl_pages_per_month_limit', -1)),
                            'topic_clusters_enabled' => (bool) ($featureValues['topic_clusters_enabled'] ?? $featureValues['content_intelligence'] ?? true),
                            'content_calendar_enabled' => (bool) ($featureValues['content_calendar_enabled'] ?? true),
                            'wordpress_automation_enabled' => (bool) ($featureValues['wordpress_automation_enabled'] ?? $featureValues['can_push_to_wp'] ?? true),
                            'priority_support' => (bool) ($featureValues['priority_support'] ?? (! $isStarter)),
                            'monthly_only' => true,
                            'has_required_onboarding' => (bool) ($onboarding['required'] ?? false),
                            'onboarding_fee_cents' => $onboardingFeeCents,
                            'llm_tracking_daily_checks' => ! $isStarter,
                        ],
                        'badge' => (string) ($plan->badge ?: Arr::get($limits, 'badge', '')),
                        'is_popular' => (bool) $plan->is_featured,
                        'cta_label' => (string) ($plan->cta_label ?? Arr::get($limits, 'cta_label', '')),
                        'cta_url' => (string) ($plan->cta_url ?? Arr::get($limits, 'cta_url', '')),
                        'highlights' => $highlights,
                        'marketing_bullets' => $this->pricingMarketingBullets(
                            $planSlug,
                            $workspaceLimit,
                            $siteLimit,
                            $userLimit
                        ),
                        'feature_groups' => $groupedDetails,
                        'sort_order' => (int) ($plan->sort_order ?? Arr::get($limits, 'sort_order', 999999)),
                    ];
                })
                ->sortBy('sort_order')
                ->values();
        });
        return $plans->values();
    }

    /**
     * Build public plan bullets from real plan limits plus safe shipped-copy.
     *
     * TODO(PRICING): Move this slug-based mapping into a dedicated pricing entitlement
     * catalog so public pricing copy can be fully data-driven without relying on
     * marketing-specific controller logic.
     *
     * @return array<int,string>
     */
    private function pricingMarketingBullets(string $planSlug, int $workspaceLimit, int $siteLimit, int $userLimit): array
    {
        return match ($planSlug) {
            'starter' => [
                __('public.landing.pricing_marketing_bullets.workspace_count', ['count' => $workspaceLimit]).', '.__('public.landing.pricing_marketing_bullets.site_count', ['count' => $siteLimit]),
                'AI-powered content generation',
                'WordPress publishing',
                'Basic SEO and llms.txt export',
            ],
            'growth' => [
                __('public.landing.pricing_marketing_bullets.workspaces_up_to', ['count' => $workspaceLimit]).', '.__('public.landing.pricing_marketing_bullets.connected_sites_up_to', ['count' => $siteLimit]),
                __('public.landing.pricing_marketing_bullets.intelligence_selected'),
                'Multi-locale publishing and automation',
                __('public.landing.pricing_marketing_bullets.team_roles_invites'),
            ],
            'scale' => [
                __('public.landing.pricing_marketing_bullets.workspaces_up_to', ['count' => $workspaceLimit]).', '.__('public.landing.pricing_marketing_bullets.connected_sites_up_to', ['count' => $siteLimit]),
                __('public.landing.pricing_marketing_bullets.intelligence_expanded'),
                'API, webhooks, and priority processing',
                __('public.landing.pricing_marketing_bullets.team_workflow_capacity'),
            ],
            default => [],
        };
    }

    private function normalizePlanLimit(mixed $value, int $default = 1): int
    {
        return is_numeric($value) ? max(0, (int) $value) : max(0, $default);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function enterprisePlan(): ?array
    {
        if (! Schema::hasTable('plans')) {
            return null;
        }

        return Cache::remember('public.landing.enterprise_plan', now()->addMinutes(5), function (): ?array {
            $plan = Plan::query()
                ->publiclyVisible()
                ->where(function ($query): void {
                    $query->customBilling()
                        ->orWhere('slug', 'enterprise')
                        ->orWhere('key', 'enterprise');
                })
                ->with('features')
                ->orderBy('sort_order')
                ->first();

            if (! $plan) {
                return null;
            }

            $allFeatures = $plan->features
                ->sortBy(fn ($row) => [(int) ($row->sort_order ?? 999999), (string) ($row->label ?? $row->feature_key)]);

            return [
                'id' => (string) $plan->id,
                'slug' => (string) ($plan->slug ?: $plan->key),
                'name' => (string) $plan->name,
                'description' => (string) ($plan->description_short ?: ''),
                'badge' => (string) ($plan->badge ?: 'Custom'),
                'cta_label' => (string) ($plan->cta_label ?: __('public.landing.contact_us')),
                'cta_url' => (string) ($plan->cta_url ?: LocalizedMarketingUrl::route('public.contact', ['subject' => 'maatwerk-enterprise'], (string) app()->getLocale()).'#contact-form'),
                'highlights' => $allFeatures
                    ->filter(fn ($row) => (bool) ($row->is_highlight ?? false))
                    ->map(fn ($row) => trim((string) ($row->label ?: $row->feature_key)))
                    ->filter()
                    ->take(6)
                    ->values()
                    ->all(),
            ];
        });
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function activeCreditPacks(): Collection
    {
        if (! Schema::hasTable('credit_packs')) {
            return collect();
        }

        return Cache::remember('public.landing.active_credit_packs', now()->addMinutes(5), function (): Collection {
            return CreditPack::query()
                ->where('is_active', true)
                ->get()
                ->map(function (CreditPack $pack): array {
                    $meta = is_array($pack->meta) ? $pack->meta : [];
                    $sortOrder = Arr::get($meta, 'sort_order');
                    if (! is_numeric($sortOrder)) {
                        $sortOrder = 999999;
                    }

                    return [
                        'id' => (string) $pack->id,
                        'key' => (string) $pack->key,
                        'name' => (string) $pack->name,
                        'credits' => (int) $pack->credits_amount,
                        'price_cents' => (int) $pack->price_cents,
                        'currency' => (string) ($pack->currency ?: 'EUR'),
                        'vat_included' => (bool) $pack->vat_included,
                        'description' => (string) Arr::get($meta, 'description', ''),
                        'is_best_value' => (bool) Arr::get($meta, 'is_best_value', false),
                        'sort_order' => (int) $sortOrder,
                    ];
                })
                ->sortBy('sort_order')
                ->values();
        });
    }
}
