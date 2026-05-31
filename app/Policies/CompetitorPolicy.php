<?php

namespace App\Policies;

use App\Models\Competitor;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class CompetitorPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_competitive_intelligence') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Competitor $competitor): Response
    {
        return $this->allows($user, 'view_competitive_intelligence', $competitor) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'view_competitive_intelligence') ? Response::allow() : Response::deny();
    }

    public function update(User $user, Competitor $competitor): Response
    {
        return $this->allows($user, 'view_competitive_intelligence', $competitor) ? Response::allow() : Response::deny();
    }

    public function delete(User $user, Competitor $competitor): Response
    {
        return $this->update($user, $competitor);
    }
}
