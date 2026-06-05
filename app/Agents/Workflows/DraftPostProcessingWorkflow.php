<?php

namespace App\Agents\Workflows;

use App\Agents\Contracts\AgentWorkflowInterface;
use App\Agents\Data\AgentContext;
use App\Agents\Data\AgentWorkflowStep;
use App\Agents\InternalLinking\InternalLinkingAgent;
use App\Agents\Localization\LocalizationAgent;
use App\Models\Draft;
use App\Services\Agents\AgentAutomationSettingsResolver;

class DraftPostProcessingWorkflow implements AgentWorkflowInterface
{
    public function __construct(
        private readonly InternalLinkingAgent $internalLinkingAgent,
        private readonly LocalizationAgent $localizationAgent,
        private readonly AgentAutomationSettingsResolver $settingsResolver,
    ) {
    }

    public function key(): string
    {
        return 'workflow.draft_post_processing';
    }

    public function supports(AgentContext $context): bool
    {
        return $context->draftId !== null;
    }

    public function steps(AgentContext $context): array
    {
        return [
            new AgentWorkflowStep(
                key: 'internal_linking',
                agent: $this->internalLinkingAgent,
                shouldRun: fn (AgentContext $stepContext, array $completedSteps) => $this->smartSuggestionsEnabled($stepContext),
            ),
            new AgentWorkflowStep(
                key: 'localization',
                agent: $this->localizationAgent,
                shouldRun: fn (AgentContext $stepContext, array $completedSteps) => $this->hasMultilingualContext($stepContext)
                    && $this->localizationChecksEnabled($stepContext),
            ),
        ];
    }

    private function smartSuggestionsEnabled(AgentContext $context): bool
    {
        $draft = Draft::query()
            ->with('clientSite.workspace')
            ->find($context->draftId);

        return $this->settingsResolver->smartSuggestionsEnabledForSite($draft?->clientSite);
    }

    private function hasMultilingualContext(AgentContext $context): bool
    {
        $draft = Draft::query()
            ->with('clientSite.workspace')
            ->find($context->draftId);

        return count($draft?->clientSite?->workspace?->getEnabledLanguagesAsEnums() ?? []) > 1;
    }

    private function localizationChecksEnabled(AgentContext $context): bool
    {
        $draft = Draft::query()
            ->with('clientSite.workspace')
            ->find($context->draftId);

        return $this->settingsResolver->localizationChecksEnabledForSite($draft?->clientSite);
    }
}
