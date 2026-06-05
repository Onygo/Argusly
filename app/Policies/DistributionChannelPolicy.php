<?php

namespace App\Policies;

use App\Models\DistributionChannel;
use App\Models\User;

class DistributionChannelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin || (bool) $user->organization_id;
    }

    public function view(User $user, DistributionChannel $channel): bool
    {
        return $user->is_admin || (int) $channel->organization_id === (int) $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin'], true);
    }

    public function update(User $user, DistributionChannel $channel): bool
    {
        return $this->view($user, $channel) && $this->create($user);
    }

    public function delete(User $user, DistributionChannel $channel): bool
    {
        return $this->update($user, $channel);
    }
}
