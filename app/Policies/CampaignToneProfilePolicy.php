<?php

namespace App\Policies;

use App\Models\CampaignToneProfile;
use App\Models\User;

class CampaignToneProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin || (bool) $user->organization_id;
    }

    public function view(User $user, CampaignToneProfile $profile): bool
    {
        return $user->is_admin || (int) $profile->organization_id === (int) $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function update(User $user, CampaignToneProfile $profile): bool
    {
        return $this->view($user, $profile) && $this->create($user);
    }

    public function delete(User $user, CampaignToneProfile $profile): bool
    {
        return $this->update($user, $profile);
    }
}
