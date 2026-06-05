<?php

namespace App\Policies;

use App\Models\CampaignCtaPreset;
use App\Models\User;

class CampaignCtaPresetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin || (bool) $user->organization_id;
    }

    public function view(User $user, CampaignCtaPreset $preset): bool
    {
        return $user->is_admin || (int) $preset->organization_id === (int) $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function update(User $user, CampaignCtaPreset $preset): bool
    {
        return $this->view($user, $preset) && $this->create($user);
    }

    public function delete(User $user, CampaignCtaPreset $preset): bool
    {
        return $this->update($user, $preset);
    }
}
