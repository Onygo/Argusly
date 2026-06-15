<?php

namespace App\Services\Assistant;

use App\Models\AssistantFeedItem;

class AssistantPrioritySystem
{
    /**
     * @param array<string,mixed> $context
     */
    public function score(float|int|null $baseScore, string $assistantState, string $category, array $context = []): int
    {
        $score = (int) round((float) ($baseScore ?? 50));

        $score += match ($assistantState) {
            AssistantFeedItem::STATE_NEEDS_INPUT => 18,
            AssistantFeedItem::STATE_RECOMMEND => 8,
            AssistantFeedItem::STATE_PREPARED => 4,
            AssistantFeedItem::STATE_COMPLETED => -18,
            default => 0,
        };

        $score += match ($category) {
            AssistantFeedItem::CATEGORY_APPROVAL => 12,
            AssistantFeedItem::CATEGORY_EXECUTION => 8,
            AssistantFeedItem::CATEGORY_OPPORTUNITY => 6,
            AssistantFeedItem::CATEGORY_LEARNING => 4,
            default => 0,
        };

        if ((bool) ($context['blocked'] ?? false)) {
            $score += 10;
        }

        if ((bool) ($context['urgent'] ?? false)) {
            $score += 8;
        }

        return max(1, min(100, $score));
    }

    public function label(int $score): string
    {
        return match (true) {
            $score >= 85 => 'critical',
            $score >= 70 => 'high',
            $score >= 40 => 'medium',
            default => 'low',
        };
    }
}
