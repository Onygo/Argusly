<?php

namespace App\Policies;

use App\Models\ResearchProject;
use App\Models\User;

class ResearchProjectPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, ResearchProject $project): bool
    {
        if ($user->is_admin) {
            return true;
        }

        $organizationId = (int) ($project->workspace?->organization_id ?? 0);

        return $organizationId === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function create(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function run(User $user, ResearchProject $project): bool
    {
        if (! $this->view($user, $project)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }
}
