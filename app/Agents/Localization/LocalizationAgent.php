<?php

namespace App\Agents\Localization;

use App\Agents\Contracts\AgentInterface;
use App\Agents\Data\AgentContext;
use App\Agents\Data\AgentResult;

class LocalizationAgent implements AgentInterface
{
    public const KEY = 'content.localization_recommendations';

    public function __construct(
        private readonly LocalizationInputBuilder $inputBuilder,
        private readonly LocalizationConsistencyChecker $checker,
        private readonly LocalizationPlanner $planner,
        private readonly LocalizationFormatter $formatter,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function supports(AgentContext $context): bool
    {
        return $context->draftId !== null || $context->contentId !== null;
    }

    public function run(AgentContext $context): AgentResult
    {
        $input = $this->inputBuilder->build($context);
        $issues = $this->checker->check($input);
        $actions = $this->planner->plan($issues);
        $formatted = $this->formatter->format($input, $issues, $actions);

        $metrics = [
            'issue_count' => count($issues),
            'high_priority_count' => collect($issues)->where('severity', 'high')->count(),
            'translation_action_count' => collect($actions)
                ->filter(fn (array $action): bool => in_array((string) ($action['type'] ?? ''), [
                    'translate_draft_locale',
                    'translate_content_locale',
                    'refresh_content_locale',
                ], true))
                ->count(),
            'resource_type' => (string) ($input['resource_type'] ?? 'content'),
            'locale' => (string) ($input['declared_locale'] ?? ''),
        ];
        $rawPayload = [
            'resource_type' => (string) ($input['resource_type'] ?? 'content'),
            'recommendations' => $formatted['recommendations'],
            'actions' => $formatted['actions'],
        ];

        if ($issues === []) {
            return AgentResult::success(
                agentKey: self::KEY,
                summary: (string) $formatted['summary'],
                suggestions: [],
                actions: $formatted['actions'],
                metrics: $metrics,
                rawPayload: $rawPayload,
            );
        }

        return AgentResult::warning(
            agentKey: self::KEY,
            summary: (string) $formatted['summary'],
            suggestions: $formatted['recommendations'],
            actions: $formatted['actions'],
            metrics: $metrics,
            rawPayload: $rawPayload,
        );
    }
}
