<?php

namespace App\Policies;

use App\Models\SignalScore;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class SignalScorePolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, SignalScore $signalScore): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $signalScore);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, SignalScore $signalScore): bool
    {
        return $this->view($user, $signalScore) && $this->canManage($user);
    }

    public function delete(User $user, SignalScore $signalScore): bool
    {
        return $this->update($user, $signalScore);
    }
}
