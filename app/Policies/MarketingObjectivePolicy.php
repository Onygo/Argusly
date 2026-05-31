<?php

namespace App\Policies;

use App\Models\MarketingObjective;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class MarketingObjectivePolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_campaigns') ? Response::allow() : Response::deny();
    }

    public function view(User $user, MarketingObjective $objective): Response
    {
        return $this->allows($user, 'view_campaigns', $objective, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_campaigns') ? Response::allow() : Response::deny();
    }

    public function update(User $user, MarketingObjective $objective): Response
    {
        return $this->allows($user, 'manage_campaigns', $objective, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }
}
