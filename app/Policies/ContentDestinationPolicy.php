<?php

namespace App\Policies;

use App\Models\ContentDestination;
use App\Models\User;

class ContentDestinationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, ContentDestination $destination): bool
    {
        return $this->canManage($user)
            && (int) ($user->organization_id ?? 0) > 0
            && (int) ($destination->workspace?->organization_id ?? 0) === (int) $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ContentDestination $destination): bool
    {
        return $this->view($user, $destination);
    }

    public function delete(User $user, ContentDestination $destination): bool
    {
        return $this->view($user, $destination);
    }

    private function canManage(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin'], true);
    }
}
