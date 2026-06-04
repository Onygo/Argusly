<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;

class PlanResolver
{
    public function subscription(Account $account): ?Subscription
    {
        return $account->activeSubscription()
            ->with(['plan.features', 'plan.entitlements', 'plan.featureLimits'])
            ->first();
    }

    public function plan(Account $account): ?Plan
    {
        return $this->subscription($account)?->plan;
    }

    public function planKey(Account $account): ?string
    {
        return $this->plan($account)?->key;
    }

    public function family(Account|Plan|string|null $plan): ?string
    {
        $key = match (true) {
            $plan instanceof Account => $this->planKey($plan),
            $plan instanceof Plan => $plan->key,
            is_string($plan) => $plan,
            default => null,
        };

        if (! $key) {
            return null;
        }

        return (string) preg_replace('/_(monthly|yearly)$/', '', $key);
    }

    public function isEnterprise(Account|Plan|string|null $plan): bool
    {
        return $this->family($plan) === 'enterprise';
    }
}
