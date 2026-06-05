<?php

namespace App\Policies;

use App\Models\ContentSeries;
use App\Models\User;

class ContentSeriesPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, ContentSeries $series): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return (int) $user->organization_id === (int) $series->organization_id;
    }

    public function create(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function update(User $user, ContentSeries $series): bool
    {
        if (! $this->view($user, $series)) {
            return false;
        }

        if (! $this->create($user)) {
            return false;
        }

        return ! $series->isLocked() && ! $series->isArchived();
    }

    public function duplicate(User $user, ContentSeries $series): bool
    {
        return $this->view($user, $series) && $this->create($user);
    }

    public function archive(User $user, ContentSeries $series): bool
    {
        if (! $this->view($user, $series)) {
            return false;
        }

        if (! $this->create($user)) {
            return false;
        }

        return $series->normalizedStatus() === ContentSeries::STATUS_PUBLISHED;
    }

    public function delete(User $user, ContentSeries $series): bool
    {
        if (! $this->view($user, $series)) {
            return false;
        }

        if (! $this->create($user)) {
            return false;
        }

        return $series->normalizedStatus() === ContentSeries::STATUS_DRAFT && ! $series->isLocked();
    }
}
