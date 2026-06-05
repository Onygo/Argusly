<?php

namespace App\Policies;

use App\Models\Draft;
use App\Models\User;

class DraftPolicy
{
    public function view(User $user, Draft $draft): bool
    {
        if ($user->is_admin) {
            return true;
        }

        $organizationId = (int) ($draft->clientSite?->workspace?->organization_id ?? 0);

        return $organizationId === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function update(User $user, Draft $draft): bool
    {
        if (! $this->view($user, $draft)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function analyze(User $user, Draft $draft): bool
    {
        return $this->update($user, $draft);
    }

    public function improve(User $user, Draft $draft): bool
    {
        return $this->update($user, $draft);
    }

    public function republish(User $user, Draft $draft): bool
    {
        return $this->update($user, $draft);
    }

    public function translate(User $user, Draft $draft): bool
    {
        return $this->update($user, $draft);
    }

    public function runAgent(User $user, Draft $draft): bool
    {
        return $this->update($user, $draft);
    }
}
