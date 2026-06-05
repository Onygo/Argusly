<?php

namespace App\Listeners\Onboarding;

use App\Events\Onboarding\DraftGenerated;
use App\Models\Draft;
use App\Services\Onboarding\OnboardingStateService;

class SyncOnboardingStateOnDraftGenerated
{
    public function __construct(private readonly OnboardingStateService $states)
    {
    }

    public function handle(DraftGenerated $event): void
    {
        $draft = Draft::query()->with('clientSite.workspace')->find($event->draftId);
        if (! $draft) {
            return;
        }

        $this->states->recordDraftActivity($draft);
    }
}

