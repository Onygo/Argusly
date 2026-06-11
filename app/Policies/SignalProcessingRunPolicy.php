<?php

namespace App\Policies;

use App\Models\SignalProcessingRun;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class SignalProcessingRunPolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, SignalProcessingRun $signalProcessingRun): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $signalProcessingRun);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, SignalProcessingRun $signalProcessingRun): bool
    {
        return $this->view($user, $signalProcessingRun) && $this->canManage($user);
    }

    public function delete(User $user, SignalProcessingRun $signalProcessingRun): bool
    {
        return $this->update($user, $signalProcessingRun);
    }
}
