<?php

namespace App\Policies;

use App\Models\PageAlert;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class PageAlertPolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, PageAlert $pageAlert): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $pageAlert);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PageAlert $pageAlert): bool
    {
        return $this->view($user, $pageAlert) && $this->canManage($user);
    }

    public function delete(User $user, PageAlert $pageAlert): bool
    {
        return $this->update($user, $pageAlert);
    }
}
