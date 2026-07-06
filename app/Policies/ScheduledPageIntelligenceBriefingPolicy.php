<?php

namespace App\Policies;

use App\Models\ScheduledPageIntelligenceBriefing;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class ScheduledPageIntelligenceBriefingPolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, ScheduledPageIntelligenceBriefing $briefing): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $briefing);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ScheduledPageIntelligenceBriefing $briefing): bool
    {
        return $this->view($user, $briefing) && $this->canManage($user);
    }

    public function delete(User $user, ScheduledPageIntelligenceBriefing $briefing): bool
    {
        return $this->update($user, $briefing);
    }
}
