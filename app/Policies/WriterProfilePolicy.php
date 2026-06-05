<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WriterProfile;

class WriterProfilePolicy
{
    public function view(User $user, WriterProfile $profile): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return (int) ($profile->workspace?->organization_id ?? 0) === (int) $user->organization_id;
    }

    public function create(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin'], true);
    }

    public function update(User $user, WriterProfile $profile): bool
    {
        return $this->create($user) && $this->view($user, $profile);
    }

    public function delete(User $user, WriterProfile $profile): bool
    {
        return $this->update($user, $profile);
    }
}
