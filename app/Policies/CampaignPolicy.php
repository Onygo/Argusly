<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class CampaignPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_campaigns') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Campaign $campaign): Response
    {
        return $this->allows($user, 'view_campaigns', $campaign) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_campaigns') ? Response::allow() : Response::deny();
    }

    public function update(User $user, Campaign $campaign): Response
    {
        return $this->allows($user, 'manage_campaigns', $campaign) ? Response::allow() : Response::deny();
    }

    public function delete(User $user, Campaign $campaign): Response
    {
        return $this->update($user, $campaign);
    }
}
