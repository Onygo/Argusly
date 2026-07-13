<?php

namespace App\Policies;

use App\Models\BrandGrowthPlanFinding;
use App\Models\User;

class BrandGrowthPlanFindingPolicy
{
    public function view(User $user, BrandGrowthPlanFinding $finding): bool
    {
        return $user->is_admin
            || ((int) ($finding->workspace?->organization_id ?? $finding->organization_id ?? 0) === (int) $user->organization_id
                && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true));
    }

    public function review(User $user, BrandGrowthPlanFinding $finding): bool
    {
        return $this->view($user, $finding)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer'], true));
    }

    public function promote(User $user, BrandGrowthPlanFinding $finding): bool
    {
        return $this->view($user, $finding)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor'], true));
    }
}
