<?php

namespace App\Listeners\Agents;

use App\Agents\EventTriggeredAgentRunner;
use App\Agents\Localization\LocalizationAgent;
use App\Events\Agents\TranslationCompleted;
use App\Models\Draft;
use App\Services\Agents\AgentAutomationSettingsResolver;

class RunLocalizationChecksAfterTranslation
{
    public function __construct(
        private readonly EventTriggeredAgentRunner $runner,
        private readonly LocalizationAgent $agent,
        private readonly AgentAutomationSettingsResolver $settingsResolver,
    ) {
    }

    public function handle(TranslationCompleted $event): void
    {
        $draft = Draft::query()
            ->with(['clientSite.workspace', 'content'])
            ->find($event->translatedDraftId);

        if (! $draft) {
            return;
        }

        if (! $this->settingsResolver->localizationChecksEnabledForSite($draft->clientSite)) {
            return;
        }

        $this->runner->runForDraft(
            agent: $this->agent,
            draft: $draft,
            triggerSource: 'event.translation_completed',
            metadata: [
                'event' => 'translation_completed',
                'surface' => 'draft_detail',
                'source_draft_id' => $event->sourceDraftId,
                'source_content_id' => $event->sourceContentId,
                'translated_content_id' => $event->translatedContentId,
                'target_locale' => $event->targetLocale,
            ],
        );
    }
}
