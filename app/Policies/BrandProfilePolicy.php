<?php

namespace App\Policies;

use App\Models\BrandProfile;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class BrandProfilePolicy
{
    use AuthorizesTenantModels;

    public function view(User $user, BrandProfile $profile): Response
    {
        return $this->allows($user, 'manage_account', $profile) ? Response::allow() : Response::deny();
    }

    public function update(User $user, BrandProfile $profile): Response
    {
        return $this->allows($user, 'manage_account', $profile) ? Response::allow() : Response::deny();
    }
}
