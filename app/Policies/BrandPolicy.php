<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;
use App\Services\PermissionService;

class BrandPolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->userCan($user, 'manage_account')
            || $this->platform($user);
    }

    public function view(User $user, Brand $brand): bool
    {
        return $this->tenant($user, $brand) || $this->platform($user);
    }

    public function create(User $user): bool
    {
        return $this->permissions->userCan($user, 'manage_account')
            || $this->platform($user);
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->tenant($user, $brand) || $this->platform($user);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->platform($user);
    }

    private function tenant(User $user, Brand $brand): bool
    {
        return $this->permissions->userCan($user, 'manage_account', [
            'account_id' => $brand->account_id,
        ]);
    }

    private function platform(User $user): bool
    {
        return $this->permissions->userCan($user, 'manage_platform', [
            'account_id' => null,
            'brand_id' => null,
        ]);
    }
}
