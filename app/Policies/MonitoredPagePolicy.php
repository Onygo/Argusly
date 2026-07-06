<?php

namespace App\Policies;

use App\Models\MonitoredPage;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class MonitoredPagePolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, MonitoredPage $monitoredPage): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $monitoredPage);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, MonitoredPage $monitoredPage): bool
    {
        return $this->view($user, $monitoredPage) && $this->canManage($user);
    }

    public function delete(User $user, MonitoredPage $monitoredPage): bool
    {
        return $this->update($user, $monitoredPage);
    }
}
