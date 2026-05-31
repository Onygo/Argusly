<?php

namespace App\Policies;

use App\Models\Briefing;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class BriefingPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_campaigns') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Briefing $briefing): Response
    {
        return $this->allows($user, 'view_campaigns', $briefing, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_campaigns') ? Response::allow() : Response::deny();
    }

    public function update(User $user, Briefing $briefing): Response
    {
        return $this->allows($user, 'manage_campaigns', $briefing, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }
}
