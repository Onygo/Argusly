<?php

namespace App\Policies;

use App\Models\Source;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class SourcePolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'manage_account') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Source $source): Response
    {
        return $this->allows($user, 'manage_account', $source) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Source $source): Response
    {
        return $this->view($user, $source);
    }

    public function delete(User $user, Source $source): Response
    {
        return $this->view($user, $source);
    }

    public function sync(User $user, Source $source): Response
    {
        return $this->update($user, $source);
    }
}
