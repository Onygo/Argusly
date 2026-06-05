<?php

namespace App\Listeners\Onboarding;

use App\Events\Onboarding\BriefCreated;
use App\Models\Brief;
use App\Services\Onboarding\OnboardingStateService;

class SyncOnboardingStateOnBriefCreated
{
    public function __construct(private readonly OnboardingStateService $states)
    {
    }

    public function handle(BriefCreated $event): void
    {
        $brief = Brief::query()->with('clientSite.workspace')->find($event->briefId);
        if (! $brief) {
            return;
        }

        $this->states->recordBriefActivity($brief);
    }
}

