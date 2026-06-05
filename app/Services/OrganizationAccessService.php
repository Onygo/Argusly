<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Support\Arr;

class OrganizationAccessService
{
    public function effectiveState(Organization $organization): string
    {
        return match ((string) $organization->status) {
            Organization::STATUS_ARCHIVED => 'archived',
            Organization::STATUS_ON_HOLD => 'deactivated',
            default => $this->commercialTier($organization),
        };
    }

    public function commercialTier(Organization $organization): string
    {
        if ($this->isEarlyBirdConfigured($organization)) {
            return Organization::ACCESS_TIER_EARLY_BIRD;
        }

        $explicit = trim((string) ($organization->access_tier ?? ''));
        if (in_array($explicit, [
            Organization::ACCESS_TIER_PAID,
            Organization::ACCESS_TIER_TRIAL,
            Organization::ACCESS_TIER_FREE,
        ], true)) {
            return $explicit;
        }

        $subscription = $this->currentSubscription($organization);

        if ($subscription?->status === 'trialing') {
            return Organization::ACCESS_TIER_TRIAL;
        }

        if ($subscription) {
            return Organization::ACCESS_TIER_PAID;
        }

        return Organization::ACCESS_TIER_FREE;
    }

    public function label(Organization $organization): string
    {
        if ($organization->isArchived()) {
            return 'Archived';
        }

        if ($organization->isOnHold()) {
            return 'Deactivated';
        }

        if ($this->isEarlyBirdExpired($organization)) {
            return 'Early Bird expired';
        }

        return $this->labelForState($this->commercialTier($organization));
    }

    public function labelForState(string $state): string
    {
        return match ($state) {
            'archived' => 'Archived',
            'deactivated' => 'Deactivated',
            Organization::ACCESS_TIER_PAID => 'Paid',
            Organization::ACCESS_TIER_EARLY_BIRD => 'Early Bird',
            Organization::ACCESS_TIER_TRIAL => 'Trial',
            Organization::ACCESS_TIER_FREE => 'Free',
            default => ucfirst(str_replace('_', ' ', $state)),
        };
    }

    public function badgeClasses(Organization $organization): string
    {
        if ($organization->isArchived()) {
            return 'border-slate-300/80 bg-slate-500/10 text-slate-700';
        }

        if ($organization->isOnHold()) {
            return 'border-amber-300/80 bg-amber-500/10 text-amber-900';
        }

        if ($this->isEarlyBirdExpired($organization)) {
            return 'border-amber-300/80 bg-amber-500/10 text-amber-900';
        }

        return match ($this->commercialTier($organization)) {
            Organization::ACCESS_TIER_PAID => 'border-blue-300/80 bg-blue-500/10 text-blue-800',
            Organization::ACCESS_TIER_EARLY_BIRD => 'border-fuchsia-300/80 bg-fuchsia-500/10 text-fuchsia-800',
            Organization::ACCESS_TIER_TRIAL => 'border-cyan-300/80 bg-cyan-500/10 text-cyan-800',
            Organization::ACCESS_TIER_FREE => 'border-border bg-background text-textSecondary',
            default => 'border-border bg-background text-textSecondary',
        };
    }

    public function isEarlyBirdConfigured(Organization $organization): bool
    {
        return (string) ($organization->access_tier ?? '') === Organization::ACCESS_TIER_EARLY_BIRD;
    }

    public function isEarlyBirdActive(Organization $organization): bool
    {
        if (! $this->isEarlyBirdConfigured($organization)) {
            return false;
        }

        if ($organization->early_bird_ends_at === null) {
            return true;
        }

        return ! $organization->early_bird_ends_at->isPast();
    }

    public function isEarlyBirdExpired(Organization $organization): bool
    {
        return $this->isEarlyBirdConfigured($organization)
            && $organization->early_bird_ends_at !== null
            && $organization->early_bird_ends_at->isPast();
    }

    public function hasPlatformAccess(Organization $organization): bool
    {
        return $this->isEarlyBirdActive($organization)
            || $this->currentSubscription($organization) !== null;
    }

    public function fallbackTierAfterEarlyBird(Organization $organization): string
    {
        $subscription = $this->currentSubscription($organization);

        if ($subscription?->status === 'trialing') {
            return Organization::ACCESS_TIER_TRIAL;
        }

        return $subscription ? Organization::ACCESS_TIER_PAID : Organization::ACCESS_TIER_FREE;
    }

    /**
     * @return array<string, mixed>
     */
    public function earlyBirdEntitlements(Organization $organization): array
    {
        $config = config('plans.early_bird', []);
        $plan = $this->resolveEarlyBirdPlan($organization, $config);
        $entitlements = $plan ? $this->entitlementsFromPlan($plan) : [];

        foreach ((array) ($config['features'] ?? []) as $key => $value) {
            $entitlements[(string) $key] = $value;
        }

        foreach ($this->mapLimitConfig((array) ($config['limits'] ?? [])) as $key => $value) {
            $entitlements[$key] = $value;
        }

        $entitlements['plan_key'] = 'early_bird';
        $entitlements['plan_name'] = 'Early Bird';
        $entitlements['base_plan_key'] = $plan ? (string) ($plan->key ?: $plan->slug) : null;

        return $entitlements;
    }

    public function earlyBirdValue(Workspace $workspace, string $featureKey): mixed
    {
        $workspace->loadMissing('organization');

        if (! $workspace->organization || ! $this->isEarlyBirdActive($workspace->organization)) {
            return null;
        }

        $entitlements = $this->earlyBirdEntitlements($workspace->organization);

        return $entitlements[$featureKey] ?? null;
    }

    public function currentSubscription(Organization $organization): ?Subscription
    {
        if ($organization->active_subscription_id) {
            $subscription = Subscription::query()
                ->with('plan')
                ->whereKey($organization->active_subscription_id)
                ->first();

            if ($subscription && $this->isCurrentSubscription($subscription)) {
                return $subscription;
            }
        }

        return Subscription::query()
            ->with('plan')
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['active', 'trialing', 'pending_mandate', 'past_due'])
            ->orderByRaw("CASE status WHEN 'active' THEN 1 WHEN 'trialing' THEN 2 WHEN 'pending_mandate' THEN 3 WHEN 'past_due' THEN 4 ELSE 9 END")
            ->orderByDesc('updated_at')
            ->first();
    }

    private function resolveEarlyBirdPlan(Organization $organization, array $config): ?Plan
    {
        $subscriptionPlan = $this->currentSubscription($organization)?->plan;
        if ($subscriptionPlan) {
            return $subscriptionPlan;
        }

        $inheritKey = trim((string) ($config['inherit_plan_key'] ?? ''));

        if ($inheritKey === '') {
            return null;
        }

        return Plan::query()
            ->where(function ($query) use ($inheritKey): void {
                $query->where('key', $inheritKey)->orWhere('slug', $inheritKey);
            })
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function entitlementsFromPlan(Plan $plan): array
    {
        $limits = is_array($plan->limits) ? $plan->limits : [];

        $derived = [
            'included_credits' => (int) ($plan->included_credits_per_interval ?: $plan->included_credits),
            'users_limit' => (int) ($plan->seat_limit ?: Arr::get($limits, 'users', 1)),
            'wp_sites_limit' => (int) Arr::get($limits, 'sites', 1),
            'workspaces_limit' => (int) Arr::get($limits, 'workspaces', 1),
            'topics_seed_keywords_limit' => (int) Arr::get($limits, 'topics_seed_keywords_limit', -1),
            'articles_per_month_limit' => (int) Arr::get($limits, 'articles_per_month_limit', Arr::get($limits, 'included_drafts_per_month', -1)),
            'llm_tracking_queries_per_month_limit' => (int) Arr::get($limits, 'llm_tracking_queries_per_month_limit', -1),
            'competitor_slots_limit' => (int) Arr::get($limits, 'competitor_slots_limit', -1),
            'seo_audit_crawl_pages_per_month_limit' => (int) Arr::get($limits, 'seo_audit_crawl_pages_per_month_limit', -1),
            'languages_limit' => (int) Arr::get($limits, 'languages_limit', -1),
        ];

        PlanFeature::query()
            ->where('plan_id', $plan->id)
            ->get()
            ->each(function (PlanFeature $feature) use (&$derived): void {
                $derived[$feature->feature_key] = $feature->typedValue();
            });

        return $derived;
    }

    /**
     * @return array<string, int>
     */
    private function mapLimitConfig(array $limits): array
    {
        $mapped = [];

        $aliases = [
            'users' => 'users_limit',
            'workspaces' => 'workspaces_limit',
            'sites' => 'wp_sites_limit',
            'wp_sites_limit' => 'wp_sites_limit',
            'articles_generated' => 'articles_per_month_limit',
            'articles_per_month_limit' => 'articles_per_month_limit',
            'llm_queries_run' => 'llm_tracking_queries_per_month_limit',
            'llm_tracking_queries_per_month_limit' => 'llm_tracking_queries_per_month_limit',
            'audit_pages_crawled' => 'seo_audit_crawl_pages_per_month_limit',
            'seo_audit_crawl_pages_per_month_limit' => 'seo_audit_crawl_pages_per_month_limit',
            'competitor_slots_limit' => 'competitor_slots_limit',
            'languages_limit' => 'languages_limit',
        ];

        foreach ($limits as $key => $value) {
            $target = $aliases[(string) $key] ?? null;

            if ($target !== null && is_numeric($value)) {
                $mapped[$target] = (int) $value;
            }
        }

        return $mapped;
    }

    private function isCurrentSubscription(Subscription $subscription): bool
    {
        return in_array((string) $subscription->status, ['active', 'trialing', 'pending_mandate', 'past_due'], true)
            && (string) $subscription->status !== 'canceled';
    }
}
