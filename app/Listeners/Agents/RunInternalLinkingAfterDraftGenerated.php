<?php

namespace App\Listeners\Agents;

use App\Agents\AgentWorkflowOrchestrator;
use App\Agents\Data\AgentContext;
use App\Agents\Support\AgentTriggerType;
use App\Agents\Workflows\DraftPostProcessingWorkflow;
use App\Events\Onboarding\DraftGenerated;
use App\Models\Draft;
use App\Services\Agents\AgentAutomationSettingsResolver;

class RunInternalLinkingAfterDraftGenerated
{
    public function __construct(
        private readonly AgentWorkflowOrchestrator $workflowOrchestrator,
        private readonly DraftPostProcessingWorkflow $workflow,
        private readonly AgentAutomationSettingsResolver $settingsResolver,
    ) {
    }

    public function handle(DraftGenerated $event): void
    {
        $draft = Draft::query()
            ->with(['clientSite.workspace', 'content'])
            ->find($event->draftId);

        if (! $draft || $draft->isTranslation() || ! $draft->content_id) {
            return;
        }

        if (! $this->settingsResolver->automaticRecommendationGenerationEnabledForSite($draft->clientSite)) {
            return;
        }

        $context = AgentContext::forDraft($draft, [
            'organization_id' => $draft->clientSite?->workspace?->organization_id,
            'workspace_id' => $draft->clientSite?->workspace_id,
            'site_id' => $draft->client_site_id,
            'content_id' => $draft->content_id,
            'trigger_type' => AgentTriggerType::EVENT,
            'trigger_source' => 'event.draft_generated',
            'metadata' => [
                'event' => 'draft_generated',
                'surface' => 'draft_detail',
            ],
        ]);

        $this->workflowOrchestrator->run($this->workflow, $context);
    }
}
