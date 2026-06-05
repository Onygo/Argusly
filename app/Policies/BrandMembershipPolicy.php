<?php

namespace App\Policies;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\BrandMembership;
use App\Models\User;
use App\Services\PermissionService;

class BrandMembershipPolicy
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->userCan($user, 'manage_users', $this->currentContext($user));
    }

    public function view(User $user, BrandMembership $membership): bool
    {
        return $this->permissions->userCan($user, 'manage_users', [
            'account_id' => $membership->account_id,
            'brand_id' => $membership->brand_id,
        ]);
    }

    public function create(User $user): bool
    {
        return $this->permissions->userCan($user, 'manage_users', $this->currentContext($user));
    }

    public function update(User $user, BrandMembership $membership): bool
    {
        return $this->permissions->userCan($user, 'manage_users', [
            'account_id' => $membership->account_id,
            'brand_id' => $membership->brand_id,
        ]);
    }

    /**
     * @return array{account_id?: int|null, brand_id?: int|null}
     */
    private function currentContext(User $user): array
    {
        return [
            'account_id' => $this->currentAccount->id($user),
            'brand_id' => $this->currentBrand->id($user),
        ];
    }
}
