<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesSignalIntelligence
{
    public function viewAny(User $user): bool
    {
        return $this->hasAccess($user);
    }

    protected function hasAccess(User $user): bool
    {
        return $user->is_admin
            || ((bool) $user->organization_id
                && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true));
    }

    protected function canManage(User $user): bool
    {
        return $user->is_admin
            || ((bool) $user->organization_id && in_array((string) $user->role, ['owner', 'admin', 'editor'], true));
    }

    protected function belongsToOrganization(User $user, Model $model): bool
    {
        if ($user->is_admin) {
            return true;
        }

        $organizationId = (int) ($model->organization_id ?? $model->workspace?->organization_id ?? 0);

        return $organizationId > 0 && $organizationId === (int) $user->organization_id;
    }
}
