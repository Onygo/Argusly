<?php

namespace App\Policies;

use App\Models\ProgrammaticPublicationPlan;
use App\Models\User;

class ProgrammaticPublicationPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, ProgrammaticPublicationPlan $plan): bool
    {
        return $user->is_admin || (
            (int) $plan->workspace?->organization_id === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true)
        );
    }

    public function update(User $user, ProgrammaticPublicationPlan $plan): bool
    {
        return $this->view($user, $plan)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor', 'member'], true));
    }

    public function approve(User $user, ProgrammaticPublicationPlan $plan): bool
    {
        return $this->view($user, $plan)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin'], true));
    }

    public function schedule(User $user, ProgrammaticPublicationPlan $plan): bool
    {
        return $this->approve($user, $plan);
    }

    public function cancel(User $user, ProgrammaticPublicationPlan $plan): bool
    {
        return $this->approve($user, $plan);
    }

    public function prepare(User $user, ProgrammaticPublicationPlan $plan): bool
    {
        return $this->update($user, $plan);
    }
}
