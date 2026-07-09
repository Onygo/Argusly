<?php

namespace App\Policies;

use App\Models\Connectors\ConnectorDataset;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class ConnectorDatasetPolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, ConnectorDataset $dataset): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $dataset);
    }

    public function create(User $user): bool
    {
        return $this->canManageConnectors($user);
    }

    public function update(User $user, ConnectorDataset $dataset): bool
    {
        return $this->view($user, $dataset) && $this->canManageConnectors($user);
    }

    public function delete(User $user, ConnectorDataset $dataset): bool
    {
        return $this->update($user, $dataset);
    }

    private function canManageConnectors(User $user): bool
    {
        return $user->is_admin
            || ((bool) $user->organization_id && in_array((string) $user->role, ['owner', 'admin'], true));
    }
}
