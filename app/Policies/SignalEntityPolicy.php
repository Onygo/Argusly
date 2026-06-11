<?php

namespace App\Policies;

use App\Models\SignalEntity;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class SignalEntityPolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, SignalEntity $signalEntity): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $signalEntity);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, SignalEntity $signalEntity): bool
    {
        return $this->view($user, $signalEntity) && $this->canManage($user);
    }

    public function delete(User $user, SignalEntity $signalEntity): bool
    {
        return $this->update($user, $signalEntity);
    }
}
