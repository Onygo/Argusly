<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class OrganizationPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_dashboard') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Organization $organization): Response
    {
        return $this->allows($user, 'view_dashboard', $organization, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_account') ? Response::allow() : Response::deny();
    }

    public function update(User $user, Organization $organization): Response
    {
        return $this->allows($user, 'manage_account', $organization, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }

    public function delete(User $user, Organization $organization): Response
    {
        return $this->update($user, $organization);
    }
}
