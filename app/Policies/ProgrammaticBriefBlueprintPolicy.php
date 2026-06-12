<?php

namespace App\Policies;

use App\Models\ProgrammaticBriefBlueprint;
use App\Models\User;

class ProgrammaticBriefBlueprintPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, ProgrammaticBriefBlueprint $blueprint): bool
    {
        return $user->is_admin || (
            (int) $blueprint->workspace?->organization_id === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true)
        );
    }

    public function update(User $user, ProgrammaticBriefBlueprint $blueprint): bool
    {
        return $this->view($user, $blueprint)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor', 'member'], true));
    }

    public function approve(User $user, ProgrammaticBriefBlueprint $blueprint): bool
    {
        return $this->view($user, $blueprint)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin'], true));
    }

    public function convert(User $user, ProgrammaticBriefBlueprint $blueprint): bool
    {
        return $this->approve($user, $blueprint);
    }

    public function prepare(User $user, ProgrammaticBriefBlueprint $blueprint): bool
    {
        return $this->update($user, $blueprint);
    }
}
