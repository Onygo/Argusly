<?php

namespace App\Listeners\Onboarding;

use App\Events\Onboarding\ContentPushedToWordPress;
use App\Models\Draft;
use App\Services\Onboarding\OnboardingStateService;

class SyncOnboardingStateOnContentPushed
{
    public function __construct(private readonly OnboardingStateService $states)
    {
    }

    public function handle(ContentPushedToWordPress $event): void
    {
        $draft = Draft::query()->with('clientSite.workspace')->find($event->draftId);
        if (! $draft) {
            return;
        }

        $this->states->recordPushActivity($draft);
    }
}

