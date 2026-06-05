<?php

namespace App\Listeners\Onboarding;

use App\Events\Onboarding\UserRegistered;
use App\Jobs\SendOnboardingEmailJob;
use App\Models\User;
use App\Services\Onboarding\OnboardingStateService;

class SyncOnboardingStateOnRegistered
{
    public function __construct(private readonly OnboardingStateService $states)
    {
    }

    public function handle(UserRegistered $event): void
    {
        $user = User::query()->find($event->userId);
        if (! $user) {
            return;
        }
        if ($user->is_admin) {
            return;
        }

        $state = $this->states->markRegistered($user, $event->workspaceId);
        if ((bool) config('publishlayer.onboarding.require_email_verification', false)) {
            SendOnboardingEmailJob::dispatch($user->id, 'verify_email');
            return;
        }

        // Product-led welcome sequence for non-verification flow.
        SendOnboardingEmailJob::dispatch($user->id, 'welcome');

        if ($state->verified_at === null) {
            $this->states->markVerified($user);
        }
    }
}
