<?php

namespace App\Policies;

use App\Models\Entity;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class EntityPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_dashboard') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Entity $entity): Response
    {
        if ($entity->account_id === null) {
            return $this->allows($user, 'view_dashboard') ? Response::allow() : Response::deny();
        }

        return $this->allows($user, 'view_dashboard', $entity, $entity->brand_id !== null) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_account') ? Response::allow() : Response::deny();
    }

    public function update(User $user, Entity $entity): Response
    {
        return $this->allows($user, 'manage_account', $entity, $entity->brand_id !== null) ? Response::allow() : Response::deny();
    }
}
