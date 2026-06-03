<?php

namespace App\Policies;

use App\Models\Narrative;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class NarrativePolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_dashboard') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Narrative $narrative): Response
    {
        return $this->allows($user, 'view_dashboard', $narrative) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_account') ? Response::allow() : Response::deny();
    }

    public function update(User $user, Narrative $narrative): Response
    {
        return $this->allows($user, 'manage_account', $narrative) ? Response::allow() : Response::deny();
    }
}
