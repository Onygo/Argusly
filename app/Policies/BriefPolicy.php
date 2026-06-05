<?php

namespace App\Policies;

use App\Models\Brief;
use App\Models\User;

class BriefPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, Brief $brief): bool
    {
        if ($user->is_admin) {
            return true;
        }

        $organizationId = (int) ($brief->clientSite?->workspace?->organization_id ?? 0);

        return $organizationId === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function create(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function update(User $user, Brief $brief): bool
    {
        if (! $this->view($user, $brief)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function archive(User $user, Brief $brief): bool
    {
        return $this->update($user, $brief);
    }

    public function generateDraft(User $user, Brief $brief): bool
    {
        return $this->update($user, $brief);
    }

    public function enhance(User $user, Brief $brief): bool
    {
        return $this->update($user, $brief);
    }

    public function createFromResearch(User $user): bool
    {
        return $this->create($user);
    }

    public function applySuggestion(User $user, Brief $brief): bool
    {
        return $this->update($user, $brief);
    }

    public function rejectSuggestion(User $user, Brief $brief): bool
    {
        return $this->update($user, $brief);
    }
}
