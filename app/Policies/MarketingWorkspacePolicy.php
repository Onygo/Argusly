<?php

namespace App\Policies;

use App\Models\MarketingWorkspace;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class MarketingWorkspacePolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_campaigns') ? Response::allow() : Response::deny();
    }

    public function view(User $user, MarketingWorkspace $workspace): Response
    {
        return $this->allows($user, 'view_campaigns', $workspace, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_campaigns') ? Response::allow() : Response::deny();
    }

    public function update(User $user, MarketingWorkspace $workspace): Response
    {
        return $this->allows($user, 'manage_campaigns', $workspace, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }
}
