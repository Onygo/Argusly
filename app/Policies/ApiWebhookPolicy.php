<?php

namespace App\Policies;

use App\Models\ApiWebhook;
use App\Models\User;

class ApiWebhookPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, ApiWebhook $webhook): bool
    {
        return $this->canManage($user)
            && (int) ($webhook->workspace?->organization_id ?? 0) === (int) ($user->organization_id ?? 0);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ApiWebhook $webhook): bool
    {
        return $this->view($user, $webhook);
    }

    public function delete(User $user, ApiWebhook $webhook): bool
    {
        return $this->view($user, $webhook);
    }

    private function canManage(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin'], true);
    }
}
