<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasAccess($user);
    }

    public function view(User $user, Campaign $campaign): bool
    {
        if (! $this->hasAccess($user)) {
            return false;
        }

        return $user->is_admin || (int) $campaign->organization_id === (int) $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->view($user, $campaign) && $this->canManage($user);
    }

    public function approve(User $user, Campaign $campaign): bool
    {
        return $this->view($user, $campaign)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'reviewer'], true));
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->update($user, $campaign);
    }

    private function hasAccess(User $user): bool
    {
        return $user->is_admin
            || ((bool) $user->organization_id
                && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true));
    }

    private function canManage(User $user): bool
    {
        return $user->is_admin
            || ((bool) $user->organization_id && in_array((string) $user->role, ['owner', 'admin', 'editor'], true));
    }
}
