<?php

namespace App\Policies;

use App\Models\TeamMember;
use App\Models\User;

class TeamMemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->organization_id !== null;
    }

    public function view(User $user, TeamMember $teamMember): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return (int) $teamMember->organization_id === (int) $user->organization_id;
    }

    public function create(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin'], true);
    }

    public function update(User $user, TeamMember $teamMember): bool
    {
        return $this->create($user) && $this->view($user, $teamMember);
    }

    public function delete(User $user, TeamMember $teamMember): bool
    {
        return $this->update($user, $teamMember);
    }
}
