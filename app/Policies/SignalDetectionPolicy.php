<?php

namespace App\Policies;

use App\Models\SignalDetection;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class SignalDetectionPolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, SignalDetection $signalDetection): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $signalDetection);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, SignalDetection $signalDetection): bool
    {
        return $this->view($user, $signalDetection) && $this->canManage($user);
    }

    public function delete(User $user, SignalDetection $signalDetection): bool
    {
        return $this->update($user, $signalDetection);
    }
}
