<?php

namespace App\Policies;

use App\Models\Mention;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class MentionPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_visibility') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Mention $mention): Response
    {
        return $this->allows($user, 'view_visibility', $mention) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_visibility') ? Response::allow() : Response::deny();
    }

    public function update(User $user, Mention $mention): Response
    {
        return $this->allows($user, 'manage_visibility', $mention) ? Response::allow() : Response::deny();
    }

    public function delete(User $user, Mention $mention): Response
    {
        return $this->update($user, $mention);
    }
}
