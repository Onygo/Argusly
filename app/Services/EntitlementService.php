<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\FeatureLimit;
use App\Models\PlanFeature;
use App\Services\Subscriptions\ModuleAccessService;

class EntitlementService
{
    public function __construct(
        private readonly PlanResolver $plans,
        private readonly ModuleAccessService $modules,
        private readonly CreditService $credits,
    ) {}

    public function hasAccess(Account $account, string $feature): bool
    {
        $override = $this->accountFeatureOverride($account, $feature);

        if ($override && $override->enabled !== null) {
            return $override->enabled;
        }

        if ($this->modules->accountHasModule($account, $feature)) {
            return true;
        }

        $plan = $this->plans->plan($account);

        if (! $plan) {
            return false;
        }

        return PlanFeature::query()
            ->where('plan_id', $plan->id)
            ->where('feature', $feature)
            ->where('enabled', true)
            ->exists()
            || $plan->entitlements()
                ->where('key', $feature)
                ->where('enabled', true)
                ->exists()
            || $plan->entitlements()
                ->where('key', "module:{$feature}")
                ->where('enabled', true)
                ->exists();
    }

    public function getLimit(Account $account, string $limitKey, ?string $feature = null): ?int
    {
        $feature ??= $this->featureForLimit($limitKey);
        $override = $this->accountLimitOverride($account, $limitKey, $feature);

        if ($override) {
            return $override->unlimited ? null : $override->value;
        }

        $plan = $this->plans->plan($account);

        if (! $plan) {
            return null;
        }

        $limit = FeatureLimit::query()
            ->where('plan_id', $plan->id)
            ->where('limit_key', $limitKey)
            ->where('feature', $feature)
            ->first()
            ?? FeatureLimit::query()
                ->where('plan_id', $plan->id)
                ->where('limit_key', $limitKey)
                ->first();

        if ($limit) {
            return $limit->unlimited ? null : $limit->value;
        }

        $legacyLimit = data_get($plan->limits, $limitKey);

        return is_numeric($legacyLimit) ? (int) $legacyLimit : null;
    }

    public function getRemaining(Account $account, string $limitKey, ?string $feature = null): ?int
    {
        if ($limitKey === 'credits') {
            $limit = $this->getLimit($account, $limitKey, $feature);
            $balance = $this->credits->balance($account);

            return $limit === null ? $balance : min($balance, $limit);
        }

        $limit = $this->getLimit($account, $limitKey, $feature);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->usage($account, $limitKey));
    }

    public function canCreate(Account $account, string $limitKey, ?string $feature = null, int $quantity = 1): bool
    {
        $remaining = $this->getRemaining($account, $limitKey, $feature);

        return $remaining === null || $remaining >= $quantity;
    }

    private function accountFeatureOverride(Account $account, string $feature): ?AccountEntitlement
    {
        return AccountEntitlement::query()
            ->active()
            ->where('account_id', $account->id)
            ->where('feature', $feature)
            ->whereNull('limit_key')
            ->latest()
            ->first();
    }

    private function accountLimitOverride(Account $account, string $limitKey, string $feature): ?AccountEntitlement
    {
        return AccountEntitlement::query()
            ->active()
            ->where('account_id', $account->id)
            ->where('limit_key', $limitKey)
            ->where(function ($query) use ($feature): void {
                $query->where('feature', $feature)->orWhereNull('feature');
            })
            ->latest()
            ->first();
    }

    private function featureForLimit(string $limitKey): string
    {
        return (string) config("subscriptions.limit_features.{$limitKey}", $limitKey);
    }

    private function usage(Account $account, string $limitKey): int
    {
        return match ($limitKey) {
            'brands' => Brand::query()
                ->where('account_id', $account->id)
                ->where('status', 'active')
                ->count(),
            'competitors' => Competitor::query()
                ->where('account_id', $account->id)
                ->where('status', 'active')
                ->count(),
            default => 0,
        };
    }
}
