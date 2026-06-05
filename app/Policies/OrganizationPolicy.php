<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function updateLegalName(User $user, Organization $organization): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can deactivate (set on hold) the organization.
     */
    public function deactivate(User $user, Organization $organization): bool
    {
        // Any admin can deactivate organizations
        return $user->is_admin;
    }

    /**
     * Determine whether the user can archive the organization.
     */
    public function archive(User $user, Organization $organization): bool
    {
        // Any admin can archive organizations
        return $user->is_admin;
    }

    /**
     * Determine whether the user can unarchive/restore the organization.
     */
    public function unarchive(User $user, Organization $organization): bool
    {
        // Any admin can unarchive organizations
        return $user->is_admin;
    }

    /**
     * Determine whether the user can permanently delete the organization.
     * Only superadmins can perform this dangerous action.
     */
    public function delete(User $user, Organization $organization): bool
    {
        // Only superadmins can delete organizations
        return $user->isSuperadmin();
    }

    /**
     * Determine whether the user can force delete an organization with dependencies.
     * This is an extremely dangerous action only for superadmins.
     */
    public function forceDelete(User $user, Organization $organization): bool
    {
        return $user->isSuperadmin();
    }
}
