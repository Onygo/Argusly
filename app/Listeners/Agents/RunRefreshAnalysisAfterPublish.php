<?php

namespace App\Listeners\Agents;

use App\Agents\AgentWorkflowOrchestrator;
use App\Agents\Data\AgentContext;
use App\Agents\Support\AgentTriggerType;
use App\Agents\Workflows\PublishedContentOptimizationWorkflow;
use App\Events\Agents\ContentPublished;
use App\Models\Content;
use App\Services\Agents\AgentAutomationSettingsResolver;
use App\Services\Agents\AutoModeActionExecutor;

class RunRefreshAnalysisAfterPublish
{
    public function __construct(
        private readonly AgentWorkflowOrchestrator $workflowOrchestrator,
        private readonly PublishedContentOptimizationWorkflow $workflow,
        private readonly AgentAutomationSettingsResolver $settingsResolver,
        private readonly AutoModeActionExecutor $autoModeActionExecutor,
    ) {
    }

    public function handle(ContentPublished $event): void
    {
        $content = Content::query()
            ->with(['workspace', 'clientSite.workspace'])
            ->find($event->contentId);

        if (! $content) {
            return;
        }

        if (! $this->settingsResolver->automaticRecommendationGenerationEnabledForSite($content->clientSite)) {
            return;
        }

        $context = AgentContext::forContent($content, [
            'organization_id' => $content->workspace?->organization_id ?? $content->clientSite?->workspace?->organization_id,
            'workspace_id' => $content->workspace_id,
            'site_id' => $content->client_site_id,
            'draft_id' => $event->draftId,
            'trigger_type' => AgentTriggerType::EVENT,
            'trigger_source' => 'event.content_published',
            'metadata' => [
                'event' => 'content_published',
                'surface' => 'content_detail',
                'publish_source' => $event->source,
            ],
        ]);

        $result = $this->workflowOrchestrator->run($this->workflow, $context);

        $this->autoModeActionExecutor->handlePublishedContentWorkflow($content, $result);
    }
}
