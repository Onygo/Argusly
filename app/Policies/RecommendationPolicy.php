<?php

namespace App\Policies;

use App\Models\Recommendation;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class RecommendationPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_dashboard') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Recommendation $recommendation): Response
    {
        return $this->allows($user, 'view_dashboard', $recommendation, $recommendation->brand_id !== null) ? Response::allow() : Response::deny();
    }

    public function update(User $user, Recommendation $recommendation): Response
    {
        return $this->view($user, $recommendation);
    }
}
