<?php

namespace App\Policies;

use App\Models\Newsletter;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class NewsletterPolicy
{
    use AuthorizesTenantModels;

    public function viewAny(User $user): Response
    {
        return $this->allows($user, 'view_campaigns') ? Response::allow() : Response::deny();
    }

    public function view(User $user, Newsletter $newsletter): Response
    {
        return $this->allows($user, 'view_campaigns', $newsletter, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }

    public function create(User $user): Response
    {
        return $this->allows($user, 'manage_campaigns') ? Response::allow() : Response::deny();
    }

    public function update(User $user, Newsletter $newsletter): Response
    {
        return $this->allows($user, 'manage_campaigns', $newsletter, requireCurrentBrand: false) ? Response::allow() : Response::deny();
    }
}
