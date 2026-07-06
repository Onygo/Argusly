<?php

namespace App\Policies;

use App\Models\AlertRule;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class AlertRulePolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, AlertRule $alertRule): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $alertRule);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, AlertRule $alertRule): bool
    {
        return $this->view($user, $alertRule) && $this->canManage($user);
    }

    public function delete(User $user, AlertRule $alertRule): bool
    {
        return $this->update($user, $alertRule);
    }
}
