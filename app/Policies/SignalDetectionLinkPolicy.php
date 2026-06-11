<?php

namespace App\Policies;

use App\Models\SignalDetectionLink;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class SignalDetectionLinkPolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, SignalDetectionLink $signalDetectionLink): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return $this->hasAccess($user)
            && (int) ($signalDetectionLink->detection?->organization_id ?? 0) === (int) $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, SignalDetectionLink $signalDetectionLink): bool
    {
        return $this->view($user, $signalDetectionLink) && $this->canManage($user);
    }

    public function delete(User $user, SignalDetectionLink $signalDetectionLink): bool
    {
        return $this->update($user, $signalDetectionLink);
    }
}
