<?php

namespace App\Policies;

use App\Models\ProgrammaticDraftReview;
use App\Models\User;

class ProgrammaticDraftReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, ProgrammaticDraftReview $review): bool
    {
        return $user->is_admin || (
            (int) $review->workspace?->organization_id === (int) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true)
        );
    }

    public function update(User $user, ProgrammaticDraftReview $review): bool
    {
        return $this->view($user, $review)
            && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer'], true));
    }
}
