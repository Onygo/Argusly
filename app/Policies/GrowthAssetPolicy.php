<?php

namespace App\Policies;

use App\Models\GrowthAsset;
use App\Models\User;

class GrowthAssetPolicy
{
    public function view(User $user, GrowthAsset $asset): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return (int) $asset->organization_id === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function update(User $user, GrowthAsset $asset): bool
    {
        if (! $this->view($user, $asset)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }
}
