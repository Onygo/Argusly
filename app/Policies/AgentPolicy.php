<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class AgentPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_agents') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Agent $agent): Response
    {
        return $this->viewAny($user);
    }

    public function run(User $user, Agent $agent): Response
    {
        return $this->allows($user, 'run_agents') ? Response::allow() : Response::deny();
    }

    public function update(User $user, Agent $agent): Response
    {
        return $this->run($user, $agent);
    }

    public function delete(User $user, Agent $agent): Response
    {
        return $this->run($user, $agent);
    }
}
