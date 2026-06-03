<?php

namespace App\Policies;

use App\Models\BrandProduct;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class BrandProductPolicy
{
    use AuthorizesTenantModels;

    public function view(User $user, BrandProduct $product): Response
    {
        return $this->allows($user, 'manage_account', $product) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_account') ? Response::allow() : Response::deny();
    }
}
