<?php

namespace App\Policies;

use App\Models\CompanyProfile;
use App\Models\User;

class CompanyProfilePolicy
{
    public function view(User $user, CompanyProfile $profile): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return $profile->workspace?->organization_id === $user->organization_id;
    }

    public function create(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin'], true);
    }

    public function update(User $user, CompanyProfile $profile): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if (! in_array((string) $user->role, ['owner', 'admin'], true)) {
            return false;
        }

        return $profile->workspace?->organization_id === $user->organization_id;
    }
}
