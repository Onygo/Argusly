<?php

namespace App\Policies;

use App\Models\ContentPageLink;
use App\Models\User;

class ContentPageLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin
            || in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, ContentPageLink $link): bool
    {
        if ($user->is_admin) {
            return true;
        }

        $organizationId = (int) ($link->workspace?->organization_id ?? 0);

        return $organizationId === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function create(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function update(User $user, ContentPageLink $link): bool
    {
        return $this->view($user, $link)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor'], true));
    }

    public function delete(User $user, ContentPageLink $link): bool
    {
        return $this->update($user, $link);
    }
}
