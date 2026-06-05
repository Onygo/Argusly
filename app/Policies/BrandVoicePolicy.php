<?php

namespace App\Policies;

use App\Models\BrandVoice;
use App\Models\User;

class BrandVoicePolicy
{
    public function view(User $user, BrandVoice $voice): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return $voice->workspace?->organization_id === $user->organization_id;
    }

    public function create(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin'], true);
    }

    public function update(User $user, BrandVoice $voice): bool
    {
        return $this->create($user) && $this->view($user, $voice);
    }

    public function delete(User $user, BrandVoice $voice): bool
    {
        return $this->update($user, $voice);
    }

    public function setDefault(User $user, BrandVoice $voice): bool
    {
        return $this->update($user, $voice);
    }
}
