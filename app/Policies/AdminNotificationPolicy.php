<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

class AdminNotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdminAreaUser();
    }

    public function view(User $user, Notification $notification): bool
    {
        if (! $user->isAdminAreaUser()) {
            return false;
        }

        if ($notification->target_scope !== Notification::TARGET_SCOPE_ADMIN || ! $notification->is_admin_only) {
            return false;
        }

        return $notification->user_id === null || (int) $notification->user_id === (int) $user->id;
    }

    public function update(User $user, Notification $notification): bool
    {
        return $this->view($user, $notification);
    }

    public function createAnnouncement(User $user): bool
    {
        return $user->isAdminAreaUser();
    }
}

