<?php

namespace App\Policies;

use App\Models\GrowthProgram;
use App\Models\User;

class GrowthProgramPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, GrowthProgram $program): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return (int) $program->organization_id === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function create(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor', 'member'], true);
    }

    public function update(User $user, GrowthProgram $program): bool
    {
        if (! $this->view($user, $program)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor', 'member'], true);
    }

    public function approve(User $user, GrowthProgram $program): bool
    {
        return $this->view($user, $program)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin'], true));
    }

    public function prepare(User $user, GrowthProgram $program): bool
    {
        return $this->update($user, $program);
    }
}
