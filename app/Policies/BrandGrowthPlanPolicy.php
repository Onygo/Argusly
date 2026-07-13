<?php

namespace App\Policies;

use App\Models\BrandGrowthPlan;
use App\Models\User;

class BrandGrowthPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin
            || in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, BrandGrowthPlan $plan): bool
    {
        return $user->is_admin
            || ((int) ($plan->workspace?->organization_id ?? $plan->organization_id ?? 0) === (int) $user->organization_id
                && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true));
    }

    public function create(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function update(User $user, BrandGrowthPlan $plan): bool
    {
        return $this->view($user, $plan)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor'], true));
    }

    public function approve(User $user, BrandGrowthPlan $plan): bool
    {
        return $this->update($user, $plan);
    }
}
