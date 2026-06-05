<?php

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\User;

class ApiKeyPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, ApiKey $apiKey): bool
    {
        return $this->canManage($user)
            && (int) ($apiKey->workspace?->organization_id ?? 0) === (int) ($user->organization_id ?? 0);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function revoke(User $user, ApiKey $apiKey): bool
    {
        return $this->view($user, $apiKey)
            && ! (bool) $apiKey->is_legacy_import;
    }

    public function delete(User $user, ApiKey $apiKey): bool
    {
        return $this->view($user, $apiKey)
            && ! (bool) $apiKey->is_legacy_import;
    }

    private function canManage(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin'], true);
    }
}
