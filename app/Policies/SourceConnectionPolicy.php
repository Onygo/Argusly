<?php

namespace App\Policies;

use App\Models\SourceConnection;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class SourceConnectionPolicy
{
    use AuthorizesTenantModels;

    public function view(User $user, SourceConnection $connection): Response
    {
        return $this->allows($user, 'manage_account', $connection) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_account') ? Response::allow() : Response::deny();
    }

    public function update(User $user, SourceConnection $connection): Response
    {
        return $this->view($user, $connection);
    }

    public function delete(User $user, SourceConnection $connection): Response
    {
        return $this->view($user, $connection);
    }
}
