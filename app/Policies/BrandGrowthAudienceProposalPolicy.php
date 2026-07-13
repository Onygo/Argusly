<?php

namespace App\Policies;

use App\Models\BrandGrowthAudienceProposal;
use App\Models\User;

class BrandGrowthAudienceProposalPolicy
{
    public function view(User $user, BrandGrowthAudienceProposal $proposal): bool
    {
        return $user->is_admin
            || ((int) ($proposal->workspace?->organization_id ?? $proposal->organization_id ?? 0) === (int) $user->organization_id
                && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true));
    }

    public function review(User $user, BrandGrowthAudienceProposal $proposal): bool
    {
        return $this->view($user, $proposal)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer'], true));
    }

    public function promote(User $user, BrandGrowthAudienceProposal $proposal): bool
    {
        return $this->view($user, $proposal)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor'], true));
    }
}
