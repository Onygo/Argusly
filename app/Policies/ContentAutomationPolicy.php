<?php

namespace App\Policies;

use App\Models\ContentAutomation;
use App\Models\User;

class ContentAutomationPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin'], true);
    }

    public function view(User $user, ContentAutomation $automation): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return (int) $automation->organization_id === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin'], true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ContentAutomation $automation): bool
    {
        return $this->view($user, $automation);
    }

    public function delete(User $user, ContentAutomation $automation): bool
    {
        return $this->view($user, $automation);
    }

    public function run(User $user, ContentAutomation $automation): bool
    {
        return $this->view($user, $automation);
    }
}
