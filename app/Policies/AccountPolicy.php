<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Services\PermissionService;

class AccountPolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->platform($user);
    }

    public function view(User $user, Account $account): bool
    {
        return $this->platform($user)
            || $this->permissions->userCan($user, 'manage_account', ['account_id' => $account->id]);
    }

    public function create(User $user): bool
    {
        return $this->platform($user);
    }

    public function update(User $user, Account $account): bool
    {
        return $this->platform($user)
            || $this->permissions->userCan($user, 'manage_account', ['account_id' => $account->id]);
    }

    public function delete(User $user, Account $account): bool
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
