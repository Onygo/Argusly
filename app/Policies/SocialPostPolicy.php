<?php

namespace App\Policies;

use App\Models\SocialPost;
use App\Models\User;

class SocialPostPolicy
{
    public function connect(User $user): bool
    {
        return $this->hasRole($user, ['owner', 'admin', 'superadmin']);
    }

    public function approve(User $user, SocialPost $post): bool
    {
        return $this->sameOrganization($user, $post) && $this->hasRole($user, ['owner', 'admin', 'editor', 'superadmin']);
    }

    public function schedule(User $user, SocialPost $post): bool
    {
        return $this->sameOrganization($user, $post) && $this->hasRole($user, ['owner', 'admin', 'superadmin']);
    }

    public function publish(User $user, SocialPost $post): bool
    {
        return $this->schedule($user, $post);
    }

    /**
     * @param array<int,string> $roles
     */
    private function hasRole(User $user, array $roles): bool
    {
        return in_array((string) $user->role, $roles, true);
    }

    private function sameOrganization(User $user, SocialPost $post): bool
    {
        return (int) $post->organization_id === (int) $user->organization_id;
    }
}
