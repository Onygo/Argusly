<?php

namespace App\Policies;

use App\Models\IntelligenceSignal;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class IntelligenceSignalPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_dashboard') ? Response::allow() : Response::deny();
    }

    public function view(User $user, IntelligenceSignal $signal): Response
    {
        return $this->allows($user, 'view_dashboard', $signal, $signal->brand_id !== null) ? Response::allow() : Response::deny();
    }

    public function update(User $user, IntelligenceSignal $signal): Response
    {
        return $this->view($user, $signal);
    }
}
