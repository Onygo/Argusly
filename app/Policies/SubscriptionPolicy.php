<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;
use App\Services\PermissionService;

class SubscriptionPolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->platform($user)
            || $this->permissions->userCan($user, 'manage_billing');
    }

    public function view(User $user, Subscription $subscription): bool
    {
        return $this->platform($user)
            || $this->permissions->userCan($user, 'manage_billing', ['account_id' => $subscription->account_id]);
    }

    public function create(User $user): bool
    {
        return $this->platform($user);
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return $this->platform($user);
    }

    public function delete(User $user, Subscription $subscription): bool
    {
        return $this->platform($user);
    }

    private function platform(User $user): bool
    {
        return $this->permissions->userCan($user, 'manage_platform', [
            'account_id' => null,
            'brand_id' => null,
        ]);
    }
}
