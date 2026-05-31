<?php

namespace App\Policies;

use App\Models\SourceSync;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class SourceSyncPolicy
{
    use AuthorizesTenantModels;

    public function view(User $user, SourceSync $sync): Response
    {
        return $this->allows($user, 'manage_account', $sync) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_account') ? Response::allow() : Response::deny();
    }

    public function update(User $user, SourceSync $sync): Response
    {
        return $this->view($user, $sync);
    }

    public function delete(User $user, SourceSync $sync): Response
    {
        return $this->view($user, $sync);
    }
}
