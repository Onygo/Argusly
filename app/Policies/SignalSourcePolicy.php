<?php

namespace App\Policies;

use App\Models\SignalSource;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class SignalSourcePolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, SignalSource $signalSource): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $signalSource);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, SignalSource $signalSource): bool
    {
        return $this->view($user, $signalSource) && $this->canManage($user);
    }

    public function delete(User $user, SignalSource $signalSource): bool
    {
        return $this->update($user, $signalSource);
    }
}
