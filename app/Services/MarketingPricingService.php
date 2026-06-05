<?php

namespace App\Services;

use App\Models\CreditPack;
use App\Models\Plan;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class MarketingPricingService
{
    private const CONTENT_CACHE_KEY = 'public.pricing.page_content.v2';
    private const PLANS_CACHE_KEY = 'public.pricing.plans.v2';
    private const ENTERPRISE_CACHE_KEY = 'public.pricing.enterprise.v2';
    private const PACKS_CACHE_KEY = 'public.pricing.packs.v2';
    private const SETTINGS_KEY = 'marketing_pricing_page';

    public function __construct(
        private readonly SiteSettingsService $settings,
    ) {}

    public function content(?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);

        /** @var array<string,mixed> $content */
        $content = Cache::remember(self::CONTENT_CACHE_KEY, now()->addMinutes(30), function (): array {
            $stored = $this->settings->get(self::SETTINGS_KEY, []);

            return is_array($stored) ? $stored : [];
        });

        $localized = data_get($content, $locale, []);

        return is_array($localized) ? $localized : [];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function fixedPlans(?string $locale = null): Collection
    {
        if (! Schema::hasTable('plans')) {
            return collect();
        }

        $locale = $this->normalizeLocale($locale);
        $content = $this->content($locale);

        /** @var Collection<int,array<string,mixed>> $plans */
        $plans = Cache::remember(self::PLANS_CACHE_KEY, now()->addMinutes(30), function (): Collection {
            return Plan::query()
                ->publiclyVisible()
                ->fixedBilling()
                ->where('interval', 'month')
                ->with(['features' => fn ($query) => $query->orderBy('sort_order')->orderBy('label')])
                ->orderBy('sort_order')
                ->orderBy('price_monthly_cents')
                ->get()
                ->map(function (Plan $plan): array {
                    $slug = (string) ($plan->slug ?: $plan->key);
                    $limits = is_array($plan->limits) ? $plan->limits : [];

                    return [
                        'id' => (string) $plan->id,
                        'slug' => $slug,
                        'key' => (string) ($plan->key ?: $slug),
                        'name' => (string) $plan->name,
                        'description' => (string) ($plan->description_short ?: ''),
                        'price_monthly_cents' => $plan->price_monthly_cents !== null
                            ? (int) $plan->price_monthly_cents
                            : ((int) ($plan->monthly_price_cents ?: $plan->price_cents)),
                        'price_yearly_cents' => $plan->price_yearly_cents !== null ? (int) $plan->price_yearly_cents : null,
                        'currency' => (string) ($plan->currency ?: 'EUR'),
                        'included_credits_monthly' => (int) ($plan->included_credits_per_interval ?: $plan->included_credits ?: 0),
                        'article_estimate_min' => $plan->article_estimate_min !== null ? (int) $plan->article_estimate_min : null,
                        'article_estimate_max' => $plan->article_estimate_max !== null ? (int) $plan->article_estimate_max : null,
                        'workspace_limit' => $plan->workspace_limit !== null
                            ? (int) $plan->workspace_limit
                            : (is_numeric($limits['workspaces'] ?? null) ? (int) $limits['workspaces'] : null),
                        'user_limit' => $plan->user_limit !== null
                            ? (int) $plan->user_limit
                            : (is_numeric($limits['users'] ?? null) ? (int) $limits['users'] : null),
                        'credit_rollover_policy' => (string) ($plan->credit_rollover_policy ?: 'none'),
                        'credit_expiry_days' => $plan->credit_expiry_days !== null ? (int) $plan->credit_expiry_days : null,
                        'is_popular' => (bool) ($plan->is_popular || $plan->is_featured),
                        'cta_label' => (string) ($plan->cta_label ?? ''),
                        'cta_url' => $plan->cta_url,
                    ];
                })
                ->values();
        });

        $planCopy = collect((array) data_get($content, 'plans', []))->keyBy('slug');

        return $plans->map(function (array $plan) use ($planCopy): array {
            $copy = (array) $planCopy->get($plan['slug'], []);

            return array_merge($plan, [
                'eyebrow' => (string) ($copy['eyebrow'] ?? ''),
                'audience' => (string) ($copy['audience'] ?? $plan['description']),
                'features' => array_values(array_filter((array) ($copy['features'] ?? []), fn ($value): bool => trim((string) $value) !== '')),
                'badge' => (string) ($copy['badge'] ?? ''),
                'cta_label' => (string) ($copy['cta_label'] ?? $plan['cta_label']),
            ]);
        })->values();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function enterprisePlan(?string $locale = null): ?array
    {
        if (! Schema::hasTable('plans')) {
            return null;
        }

        $locale = $this->normalizeLocale($locale);
        $content = $this->content($locale);

        /** @var array<string,mixed>|null $plan */
        $plan = Cache::remember(self::ENTERPRISE_CACHE_KEY, now()->addMinutes(30), function (): ?array {
            $enterprise = Plan::query()
                ->publiclyVisible()
                ->where(function ($query): void {
                    $query->customBilling()
                        ->orWhere('slug', 'enterprise')
                        ->orWhere('key', 'enterprise');
                })
                ->orderBy('sort_order')
                ->first();

            if (! $enterprise) {
                return null;
            }

            return [
                'id' => (string) $enterprise->id,
                'slug' => (string) ($enterprise->slug ?: $enterprise->key),
                'name' => (string) $enterprise->name,
                'description' => (string) ($enterprise->description_short ?: ''),
                'cta_label' => (string) ($enterprise->cta_label ?: ''),
                'cta_url' => $enterprise->cta_url,
            ];
        });

        if ($plan === null) {
            return null;
        }

        $copy = (array) data_get($content, 'enterprise', []);

        return array_merge($plan, [
            'audience' => (string) ($copy['audience'] ?? $plan['description']),
            'features' => array_values(array_filter((array) ($copy['features'] ?? []), fn ($value): bool => trim((string) $value) !== '')),
            'badge' => (string) ($copy['badge'] ?? ''),
            'price_label' => (string) ($copy['price_label'] ?? ''),
            'cta_label' => (string) ($copy['cta_label'] ?? $plan['cta_label']),
            'cta_url' => (string) ($plan['cta_url'] ?: LocalizedMarketingUrl::route('public.contact', ['subject' => 'enterprise-pricing'], $locale) . '#contact-form'),
        ]);
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function creditPacks(?string $locale = null): Collection
    {
        if (! Schema::hasTable('credit_packs')) {
            return collect();
        }

        $locale = $this->normalizeLocale($locale);
        $content = $this->content($locale);

        /** @var Collection<int,array<string,mixed>> $packs */
        $packs = Cache::remember(self::PACKS_CACHE_KEY, now()->addMinutes(30), function (): Collection {
            return CreditPack::query()
                ->where('is_active', true)
                ->orderBy('credits_amount')
                ->get()
                ->map(function (CreditPack $pack): array {
                    $meta = is_array($pack->meta) ? $pack->meta : [];

                    return [
                        'id' => (string) $pack->id,
                        'key' => (string) $pack->key,
                        'name' => (string) $pack->name,
                        'credits' => (int) $pack->credits_amount,
                        'price_cents' => (int) $pack->price_cents,
                        'currency' => (string) ($pack->currency ?: 'EUR'),
                        'expires_in_months' => $pack->never_expires ? null : (int) ($pack->expires_in_months ?? 12),
                        'is_best_value' => (bool) Arr::get($meta, 'is_best_value', false),
                        'sort_order' => (int) Arr::get($meta, 'sort_order', 999999),
                    ];
                })
                ->sortBy('sort_order')
                ->values();
        });

        $packCopy = collect((array) data_get($content, 'credit_packs.cards', []))->keyBy('key');

        return $packs->map(function (array $pack) use ($packCopy): array {
            $copy = (array) $packCopy->get($pack['key'], []);

            return array_merge($pack, [
                'description' => (string) ($copy['description'] ?? ''),
                'badge' => (string) ($copy['badge'] ?? ''),
            ]);
        })->values();
    }

    public function clearCaches(): void
    {
        foreach ([self::CONTENT_CACHE_KEY, self::PLANS_CACHE_KEY, self::ENTERPRISE_CACHE_KEY, self::PACKS_CACHE_KEY, 'public.pricing.page_content', 'public.landing.active_plans', 'public.landing.enterprise_plan', 'public.landing.active_credit_packs'] as $key) {
            Cache::forget($key);
        }
    }

    private function normalizeLocale(?string $locale): string
    {
        $resolved = strtolower(trim((string) $locale));

        return in_array($resolved, ['nl', 'en'], true) ? $resolved : strtolower((string) app()->getLocale() ?: 'en');
    }
}
