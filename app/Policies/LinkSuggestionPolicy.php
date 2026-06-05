<?php

namespace App\Policies;

use App\Models\LinkSuggestion;
use App\Models\User;

class LinkSuggestionPolicy
{
    public function review(User $user, LinkSuggestion $suggestion): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if (! in_array($user->role, ['editor', 'admin', 'owner'], true)) {
            return false;
        }

        return $suggestion->sourceWorkspace?->organization_id === $user->organization_id;
    }

    public function apply(User $user, LinkSuggestion $suggestion): bool
    {
        return $this->review($user, $suggestion);
    }
}
