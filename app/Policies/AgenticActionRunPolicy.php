<?php

namespace App\Policies;

use App\Models\AgenticActionRun;
use App\Models\User;

class AgenticActionRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasAppAccess($user);
    }

    public function view(User $user, AgenticActionRun $run): bool
    {
        if (! $this->hasAppAccess($user)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        $run->loadMissing('workspace');

        return $run->workspace?->organization_id !== null
            && (int) $run->workspace->organization_id === (int) $user->organization_id;
    }

    public function approve(User $user, AgenticActionRun $run): bool
    {
        return $this->view($user, $run)
            && ! $user->is_admin
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer'], true);
    }

    public function reject(User $user, AgenticActionRun $run): bool
    {
        return $this->approve($user, $run);
    }

    public function requestChanges(User $user, AgenticActionRun $run): bool
    {
        return $this->approve($user, $run);
    }

    public function run(User $user, AgenticActionRun $run): bool
    {
        return $this->approve($user, $run);
    }

    public function bulkApprove(User $user): bool
    {
        return ! $user->is_admin
            && (bool) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    private function hasAppAccess(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return (bool) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }
}
