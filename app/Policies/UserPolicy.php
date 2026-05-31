<?php

namespace App\Policies;

use App\Models\User;
use App\Services\PermissionService;

class UserPolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->userCan($user, 'manage_users');
    }

    public function view(User $user, User $model): bool
    {
        return $user->is($model) || $this->permissions->userCan($user, 'manage_users');
    }

    public function create(User $user): bool
    {
        return $this->permissions->userCan($user, 'manage_users');
    }

    public function update(User $user, User $model): bool
    {
        return $this->permissions->userCan($user, 'manage_users');
    }

    public function delete(User $user, User $model): bool
    {
        return $this->permissions->userCan($user, 'manage_users');
    }
}
