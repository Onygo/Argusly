<?php

namespace App\Policies;

use App\Models\SignalMention;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class SignalMentionPolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, SignalMention $signalMention): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $signalMention);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, SignalMention $signalMention): bool
    {
        return $this->view($user, $signalMention) && $this->canManage($user);
    }

    public function delete(User $user, SignalMention $signalMention): bool
    {
        return $this->update($user, $signalMention);
    }
}
