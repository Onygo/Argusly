<?php

namespace App\Policies;

use App\Models\Segment;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class SegmentPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_campaigns') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Segment $segment): Response
    {
        return $this->allows($user, 'view_campaigns', $segment, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_campaigns') ? Response::allow() : Response::deny();
    }

    public function update(User $user, Segment $segment): Response
    {
        return $this->allows($user, 'manage_campaigns', $segment, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }
}
