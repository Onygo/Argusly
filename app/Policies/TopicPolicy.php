<?php

namespace App\Policies;

use App\Models\Topic;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class TopicPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_dashboard') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Topic $topic): Response
    {
        return $this->allows($user, 'view_dashboard', $topic) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_account') ? Response::allow() : Response::deny();
    }

    public function update(User $user, Topic $topic): Response
    {
        return $this->allows($user, 'manage_account', $topic) ? Response::allow() : Response::deny();
    }

    public function delete(User $user, Topic $topic): Response
    {
        return $this->update($user, $topic);
    }
}
