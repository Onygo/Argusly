<?php

namespace App\Listeners\Onboarding;

use App\Events\Onboarding\UserFirstLogin;
use App\Models\User;
use App\Services\Onboarding\OnboardingStateService;

class SyncOnboardingStateOnFirstLogin
{
    public function __construct(private readonly OnboardingStateService $states)
    {
    }

    public function handle(UserFirstLogin $event): void
    {
        $user = User::query()->find($event->userId);
        if (! $user) {
            return;
        }
        if ($user->is_admin) {
            return;
        }

        $this->states->markFirstLogin($user);
    }
}
