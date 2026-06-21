<?php

namespace App\Policies;

use App\Models\FaqQuestion;
use App\Models\User;

class FaqQuestionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageFaqIntelligence($user);
    }

    public function view(User $user, FaqQuestion $faqQuestion): bool
    {
        return $this->canManageFaqIntelligence($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageFaqIntelligence($user);
    }

    public function update(User $user, FaqQuestion $faqQuestion): bool
    {
        return $this->canManageFaqIntelligence($user);
    }

    public function delete(User $user, FaqQuestion $faqQuestion): bool
    {
        return $this->canManageFaqIntelligence($user);
    }

    private function canManageFaqIntelligence(User $user): bool
    {
        return method_exists($user, 'isAdminAreaUser') && $user->isAdminAreaUser();
    }
}
