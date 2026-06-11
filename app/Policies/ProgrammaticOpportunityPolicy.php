<?php

namespace App\Policies;

use App\Models\ProgrammaticOpportunity;
use App\Models\User;

class ProgrammaticOpportunityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, ProgrammaticOpportunity $opportunity): bool
    {
        return $user->is_admin || (
            (int) $opportunity->organization_id === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true)
        );
    }

    public function update(User $user, ProgrammaticOpportunity $opportunity): bool
    {
        return $this->view($user, $opportunity)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor'], true));
    }
}
