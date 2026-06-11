<?php

namespace App\Policies;

use App\Models\ProgrammaticCluster;
use App\Models\User;

class ProgrammaticClusterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, ProgrammaticCluster $cluster): bool
    {
        return $user->is_admin || (
            (int) $cluster->organization_id === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true)
        );
    }

    public function update(User $user, ProgrammaticCluster $cluster): bool
    {
        return $this->view($user, $cluster)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor'], true));
    }
}
