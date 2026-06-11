<?php

namespace App\Policies;

use App\Models\SignalEvent;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class SignalEventPolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, SignalEvent $signalEvent): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $signalEvent);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, SignalEvent $signalEvent): bool
    {
        return $this->view($user, $signalEvent) && $this->canManage($user);
    }

    public function delete(User $user, SignalEvent $signalEvent): bool
    {
        return $this->update($user, $signalEvent);
    }
}
