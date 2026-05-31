<?php

namespace App\Policies;

use App\Models\MarketingTask;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class MarketingTaskPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_campaigns') ? Response::allow() : Response::deny();
    }

    public function view(User $user, MarketingTask $task): Response
    {
        return $this->allows($user, 'view_campaigns', $task, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_campaigns') ? Response::allow() : Response::deny();
    }

    public function update(User $user, MarketingTask $task): Response
    {
        return $this->allows($user, 'manage_campaigns', $task, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }
}
