<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class ContactPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_dashboard') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Contact $contact): Response
    {
        return $this->allows($user, 'view_dashboard', $contact, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_account') ? Response::allow() : Response::deny();
    }

    public function update(User $user, Contact $contact): Response
    {
        return $this->allows($user, 'manage_account', $contact, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }

    public function delete(User $user, Contact $contact): Response
    {
        return $this->update($user, $contact);
    }
}
