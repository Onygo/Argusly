<?php

namespace App\Policies;

use App\Models\ProgrammaticDraftRequest;
use App\Models\User;

class ProgrammaticDraftRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, ProgrammaticDraftRequest $request): bool
    {
        return $user->is_admin || (
            (int) $request->workspace?->organization_id === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true)
        );
    }

    public function update(User $user, ProgrammaticDraftRequest $request): bool
    {
        return $this->view($user, $request)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer'], true));
    }
}
