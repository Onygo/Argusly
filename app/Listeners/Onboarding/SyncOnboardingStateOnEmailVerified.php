<?php

namespace App\Listeners\Onboarding;

use App\Events\Onboarding\UserEmailVerified;
use App\Models\User;
use App\Services\Onboarding\OnboardingStateService;

class SyncOnboardingStateOnEmailVerified
{
    public function __construct(private readonly OnboardingStateService $states)
    {
    }

    public function handle(UserEmailVerified $event): void
    {
        $user = User::query()->find($event->userId);
        if (! $user) {
            return;
        }
        if ($user->is_admin) {
            return;
        }

        $this->states->markVerified($user);
    }
}
