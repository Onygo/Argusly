<?php

namespace App\Policies;

use App\Models\Connectors\ConnectorAccount;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class ConnectorAccountPolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, ConnectorAccount $account): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $account);
    }

    public function create(User $user): bool
    {
        return $this->canManageConnectors($user);
    }

    public function update(User $user, ConnectorAccount $account): bool
    {
        return $this->view($user, $account) && $this->canManageConnectors($user);
    }

    public function delete(User $user, ConnectorAccount $account): bool
    {
        return $this->update($user, $account);
    }

    private function canManageConnectors(User $user): bool
    {
        return $user->is_admin
            || ((bool) $user->organization_id && in_array((string) $user->role, ['owner', 'admin'], true));
    }
}
