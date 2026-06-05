<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function viewContentNetwork(User $user, Workspace $workspace): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return (int) $user->organization_id === (int) $workspace->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function runContentNetworkAnalysis(User $user, Workspace $workspace): bool
    {
        if (! $this->viewContentNetwork($user, $workspace)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function viewContentIntelligence(User $user, Workspace $workspace): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return (int) $user->organization_id === (int) $workspace->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function runContentIntelligenceAudit(User $user, Workspace $workspace): bool
    {
        if (! $this->viewContentIntelligence($user, $workspace)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function updateName(User $user, Workspace $workspace): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return (int) $user->organization_id === (int) $workspace->organization_id
            && (string) $user->role === 'owner';
    }
}
