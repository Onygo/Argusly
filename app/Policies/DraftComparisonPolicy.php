<?php

namespace App\Policies;

use App\Models\DraftComparison;
use App\Models\User;

class DraftComparisonPolicy
{
    public function view(User $user, DraftComparison $comparison): bool
    {
        if ($user->is_admin) {
            return true;
        }

        $organizationId = (int) ($comparison->clientSite?->workspace?->organization_id ?? 0);

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

    public function update(User $user, DraftComparison $comparison): bool
    {
        if (! $this->view($user, $comparison)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function start(User $user, DraftComparison $comparison): bool
    {
        return $this->update($user, $comparison);
    }

    public function viewStatus(User $user, DraftComparison $comparison): bool
    {
        return $this->view($user, $comparison);
    }

    public function selectWinner(User $user, DraftComparison $comparison): bool
    {
        return $this->update($user, $comparison);
    }

    public function queueHybrid(User $user, DraftComparison $comparison): bool
    {
        return $this->update($user, $comparison);
    }

    public function openVariantDraft(User $user, DraftComparison $comparison): bool
    {
        return $this->view($user, $comparison);
    }
}
