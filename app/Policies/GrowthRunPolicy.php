<?php

namespace App\Policies;

use App\Models\GrowthRun;
use App\Models\User;

class GrowthRunPolicy
{
    public function view(User $user, GrowthRun $run): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return (int) $run->organization_id === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function update(User $user, GrowthRun $run): bool
    {
        if (! $this->view($user, $run)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }
}
