<?php

namespace App\Policies;

use App\Contracts\CurrentAccountContract;
use App\Models\Membership;
use App\Models\User;
use App\Services\PermissionService;

class MembershipPolicy
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly CurrentAccountContract $currentAccount,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->userCan($user, 'manage_users', [
            'account_id' => $this->currentAccount->id($user),
        ]);
    }

    public function view(User $user, Membership $membership): bool
    {
        return $this->permissions->userCan($user, 'manage_users', ['account_id' => $membership->account_id]);
    }

    public function update(User $user, Membership $membership): bool
    {
        return $this->permissions->userCan($user, 'manage_users', ['account_id' => $membership->account_id]);
    }
}
