<?php

namespace App\Policies;

use App\Models\BrandNarrative;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class BrandNarrativePolicy
{
    use AuthorizesTenantModels;

    public function view(User $user, BrandNarrative $narrative): Response
    {
        return $this->allows($user, 'manage_account', $narrative) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_account') ? Response::allow() : Response::deny();
    }
}
