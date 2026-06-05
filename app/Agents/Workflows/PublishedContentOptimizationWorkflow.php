<?php

namespace App\Agents\Workflows;

use App\Agents\ContentRefresh\ContentRefreshAgent;
use App\Agents\Contracts\AgentWorkflowInterface;
use App\Agents\Data\AgentContext;
use App\Agents\Data\AgentWorkflowStep;
use App\Agents\Localization\LocalizationAgent;
use App\Models\Content;
use App\Services\Agents\AgentAutomationSettingsResolver;

class PublishedContentOptimizationWorkflow implements AgentWorkflowInterface
{
    public function __construct(
        private readonly ContentRefreshAgent $contentRefreshAgent,
        private readonly LocalizationAgent $localizationAgent,
        private readonly AgentAutomationSettingsResolver $settingsResolver,
    ) {
    }

    public function key(): string
    {
        return 'workflow.published_content_optimization';
    }

    public function supports(AgentContext $context): bool
    {
        return $context->contentId !== null;
    }

    public function steps(AgentContext $context): array
    {
        return [
            new AgentWorkflowStep(
                key: 'refresh_recommendations',
                agent: $this->contentRefreshAgent,
            ),
            new AgentWorkflowStep(
                key: 'localization',
                agent: $this->localizationAgent,
                shouldRun: fn (AgentContext $stepContext, array $completedSteps) => $this->hasMultilingualContext($stepContext)
                    && $this->localizationChecksEnabled($stepContext),
            ),
        ];
    }

    private function hasMultilingualContext(AgentContext $context): bool
    {
        $content = Content::query()
            ->with('workspace')
            ->find($context->contentId);

        return count($content?->workspace?->getEnabledLanguagesAsEnums() ?? []) > 1;
    }

    private function localizationChecksEnabled(AgentContext $context): bool
    {
        $content = Content::query()
            ->with('clientSite.workspace')
            ->find($context->contentId);

        return $this->settingsResolver->localizationChecksEnabledForSite($content?->clientSite);
    }
}
