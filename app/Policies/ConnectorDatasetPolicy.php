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
        return $this->canManage($user);
    }

    public function update(User $user, ConnectorDataset $dataset): bool
    {
        return $this->view($user, $dataset) && $this->canManage($user);
    }

    public function delete(User $user, ConnectorDataset $dataset): bool
    {
        return $this->update($user, $dataset);
    }
}
