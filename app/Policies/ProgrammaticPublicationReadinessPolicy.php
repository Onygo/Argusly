<?php

namespace App\Policies;

use App\Models\ProgrammaticPublicationReadiness;
use App\Models\User;

class ProgrammaticPublicationReadinessPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, ProgrammaticPublicationReadiness $readiness): bool
    {
        return $user->is_admin || (
            (int) $readiness->workspace?->organization_id === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true)
        );
    }

    public function update(User $user, ProgrammaticPublicationReadiness $readiness): bool
    {
        return $this->view($user, $readiness)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor', 'member'], true));
    }

    public function approve(User $user, ProgrammaticPublicationReadiness $readiness): bool
    {
        return $this->view($user, $readiness)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin'], true));
    }

    public function prepare(User $user, ProgrammaticPublicationReadiness $readiness): bool
    {
        return $this->update($user, $readiness);
    }
}
