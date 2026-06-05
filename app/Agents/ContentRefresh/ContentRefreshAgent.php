<?php

namespace App\Agents\ContentRefresh;

use App\Agents\Contracts\AgentInterface;
use App\Agents\Data\AgentContext;
use App\Agents\Data\AgentResult;

class ContentRefreshAgent implements AgentInterface
{
    public const KEY = 'content.refresh_recommendations';

    public function __construct(
        private readonly ContentRefreshInputBuilder $inputBuilder,
        private readonly ContentRefreshScorer $scorer,
        private readonly ContentRefreshPlanner $planner,
        private readonly ContentRefreshFormatter $formatter,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function supports(AgentContext $context): bool
    {
        return $context->contentId !== null;
    }

    public function run(AgentContext $context): AgentResult
    {
        $input = $this->inputBuilder->build($context);
        $scorecard = $this->scorer->score($input);
        $plan = $this->planner->plan($input, $scorecard);
        $formatted = $this->formatter->format($input, $scorecard, $plan);

        $reasons = collect((array) ($formatted['reasons'] ?? []))
            ->map(fn (array $reason): array => [
                'title' => (string) ($reason['title'] ?? 'Reason'),
                'description' => (string) ($reason['description'] ?? ''),
            ])
            ->values()
            ->all();
        $actions = collect((array) ($formatted['suggested_actions'] ?? []))
            ->map(fn (array $action): array => [
                'title' => (string) ($action['title'] ?? 'Action'),
                'description' => (string) ($action['description'] ?? ''),
                'href' => (string) ($action['href'] ?? ''),
            ])
            ->values()
            ->all();

        return AgentResult::success(
            agentKey: self::KEY,
            summary: (string) $formatted['summary'],
            suggestions: $reasons,
            actions: $actions,
            warnings: (array) ($formatted['warnings'] ?? []),
            metrics: [
                'refresh_score' => (int) ($formatted['refresh_score'] ?? 0),
                'urgency_level' => (string) ($formatted['urgency_level'] ?? 'low'),
                'reason_count' => count($reasons),
                'action_count' => count($actions),
            ],
            rawPayload: [
                'refresh_score' => (int) ($formatted['refresh_score'] ?? 0),
                'urgency_level' => (string) ($formatted['urgency_level'] ?? 'low'),
                'reasons' => (array) ($formatted['reasons'] ?? []),
                'suggested_actions' => (array) ($formatted['suggested_actions'] ?? []),
                'latest_draft_id' => $input['latest_draft']?->id,
            ],
        );
    }
}
