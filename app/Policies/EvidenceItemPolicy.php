<?php

namespace App\Policies;

use App\Models\EvidenceItem;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantModels;
use Illuminate\Auth\Access\Response;

class EvidenceItemPolicy
{
    use AuthorizesTenantModels;

    public function view(User $user, EvidenceItem $evidence): Response
    {
        return $this->allows($user, 'view_dashboard', $evidence, $evidence->brand_id !== null) ? Response::allow() : Response::deny();
    }
}
