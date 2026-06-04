<?php

namespace App\Services;

use App\Models\Account;

class FeatureGate
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function hasAccess(Account $account, string $feature): bool
    {
        return $this->entitlements->hasAccess($account, $feature);
    }

    public function getLimit(Account $account, string $limitKey, ?string $feature = null): ?int
    {
        return $this->entitlements->getLimit($account, $limitKey, $feature);
    }

    public function getRemaining(Account $account, string $limitKey, ?string $feature = null): ?int
    {
        return $this->entitlements->getRemaining($account, $limitKey, $feature);
    }
}
