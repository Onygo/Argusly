<?php

namespace App\Policies;

use App\Models\Connectors\ConnectorSyncRun;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class ConnectorSyncRunPolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, ConnectorSyncRun $run): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $run);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ConnectorSyncRun $run): bool
    {
        return $this->view($user, $run) && $this->canManage($user);
    }

    public function delete(User $user, ConnectorSyncRun $run): bool
    {
        return $this->update($user, $run);
    }
}
