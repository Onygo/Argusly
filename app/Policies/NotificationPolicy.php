<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->is_admin && (int) ($user->organization_id ?? 0) > 0;
    }

    public function view(User $user, Notification $notification): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($notification->target_scope !== Notification::TARGET_SCOPE_WORKSPACE || $notification->is_admin_only) {
            return false;
        }

        $workspaceOrganizationId = (int) ($notification->workspace?->organization_id ?? 0);
        if ($workspaceOrganizationId !== (int) $user->organization_id) {
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
