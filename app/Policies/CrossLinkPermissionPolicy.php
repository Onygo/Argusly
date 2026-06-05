<?php

namespace App\Policies;

use App\Models\CrossLinkPermission;
use App\Models\User;

class CrossLinkPermissionPolicy
{
    public function approve(User $user, CrossLinkPermission $permission): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array($user->role, ['admin', 'owner'], true)
            && $permission->toWorkspace?->organization_id === $user->organization_id;
    }

    public function manage(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array($user->role, ['owner', 'admin'], true);
    }
}
