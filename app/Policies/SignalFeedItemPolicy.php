<?php

namespace App\Policies;

use App\Models\SignalFeedItem;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class SignalFeedItemPolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, SignalFeedItem $signalFeedItem): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $signalFeedItem);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, SignalFeedItem $signalFeedItem): bool
    {
        return $this->view($user, $signalFeedItem) && $this->canManage($user);
    }

    public function delete(User $user, SignalFeedItem $signalFeedItem): bool
    {
        return $this->update($user, $signalFeedItem);
    }
}
