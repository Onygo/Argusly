<?php

namespace App\Policies;

use App\Models\MonitoredSource;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class MonitoredSourcePolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, MonitoredSource $monitoredSource): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $monitoredSource);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, MonitoredSource $monitoredSource): bool
    {
        return $this->view($user, $monitoredSource) && $this->canManage($user);
    }

    public function delete(User $user, MonitoredSource $monitoredSource): bool
    {
        return $this->update($user, $monitoredSource);
    }
}
