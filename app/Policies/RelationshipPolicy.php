<?php

namespace App\Policies;

use App\Models\Relationship;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class RelationshipPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_dashboard') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Relationship $relationship): Response
    {
        return $this->allows($user, 'view_dashboard', $relationship, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_account') ? Response::allow() : Response::deny();
    }

    public function update(User $user, Relationship $relationship): Response
    {
        return $this->allows($user, 'manage_account', $relationship, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }

    public function delete(User $user, Relationship $relationship): Response
    {
        return $this->update($user, $relationship);
    }
}
