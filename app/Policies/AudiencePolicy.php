<?php

namespace App\Policies;

use App\Models\Audience;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class AudiencePolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_campaigns') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Audience $audience): Response
    {
        return $this->allows($user, 'view_campaigns', $audience, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_campaigns') ? Response::allow() : Response::deny();
    }

    public function update(User $user, Audience $audience): Response
    {
        return $this->allows($user, 'manage_campaigns', $audience, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }
}
