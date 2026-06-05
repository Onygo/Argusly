<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;

class RolePolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->platform($user);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->platform($user);
    }

    public function create(User $user): bool
    {
        return $this->platform($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $this->platform($user);
    }

    public function delete(User $user, Role $role): bool
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
