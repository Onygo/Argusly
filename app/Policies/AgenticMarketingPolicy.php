<?php

namespace App\Policies;

use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AgenticMarketingPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasAppAccess($user);
    }

    public function view(User $user, Model $model): bool
    {
        if (! $this->hasAppAccess($user)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        $organizationId = $this->organizationIdFor($model);

        return $organizationId !== null && $organizationId === (int) $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->view($user, $model) && $this->canManage($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    public function approve(User $user, AgenticMarketingAction $action): bool
    {
        return $this->view($user, $action);
    }

    public function dismiss(User $user, AgenticMarketingAction $action): bool
    {
        return $this->view($user, $action);
    }

    public function execute(User $user, AgenticMarketingAction $action): bool
    {
        return $this->view($user, $action);
    }

    public function retry(User $user, AgenticMarketingAction $action): bool
    {
        return $this->view($user, $action);
    }

    private function hasAppAccess(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return (bool) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    private function canManage(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return (bool) $user->organization_id
            && in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    private function organizationIdFor(Model $model): ?int
    {
        if ($model instanceof AgenticMarketingObjective) {
            return $model->organization_id !== null ? (int) $model->organization_id : null;
        }

        if (
            $model instanceof AgenticMarketingAction
            || $model instanceof AgenticMarketingOpportunity
            || $model instanceof AgenticMarketingRun
        ) {
            $model->loadMissing('objective');

            return $model->objective?->organization_id !== null
                ? (int) $model->objective->organization_id
                : null;
        }

        return null;
    }
}
