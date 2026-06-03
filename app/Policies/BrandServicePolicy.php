<?php

namespace App\Policies;

use App\Models\BrandService;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class BrandServicePolicy
{
    use AuthorizesTenantModels;

    public function view(User $user, BrandService $service): Response
    {
        return $this->allows($user, 'manage_account', $service) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_account') ? Response::allow() : Response::deny();
    }
}
