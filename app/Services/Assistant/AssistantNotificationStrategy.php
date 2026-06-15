<?php

namespace App\Services\Assistant;

use App\Models\AssistantFeedItem;
use App\Models\Notification;

class AssistantNotificationStrategy
{
    public function notifyIfNeeded(AssistantFeedItem $item): ?Notification
    {
        if (! $this->shouldNotify($item)) {
            return null;
        }

        $existing = Notification::query()
            ->where('type', Notification::TYPE_ACTION_REQUIRED)
            ->where('workspace_id', $item->workspace_id)
            ->where('meta->assistant_feed_item_id', (string) $item->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return Notification::query()->create([
            'workspace_id' => $item->workspace_id,
            'target_scope' => Notification::TARGET_SCOPE_WORKSPACE,
            'type' => Notification::TYPE_ACTION_REQUIRED,
            'title' => $item->title,
            'body' => $item->i_need_your_input ?: $item->summary,
            'cta_label' => $item->primary_cta_label,
            'cta_url' => $item->primary_cta_url,
            'priority' => max(Notification::PRIORITY_ACTION_REQUIRED, (int) $item->priority_score),
            'meta' => [
                'assistant_feed_item_id' => (string) $item->id,
                'assistant_state' => $item->assistant_state,
                'assistant_priority_label' => $item->priority_label,
                'source_type' => $item->source_type,
                'source_id' => $item->source_id,
            ],
        ]);
    }

    private function shouldNotify(AssistantFeedItem $item): bool
    {
        return $item->workspace_id
            && $item->status === AssistantFeedItem::STATUS_ACTIVE
            && $item->assistant_state === AssistantFeedItem::STATE_NEEDS_INPUT
            && in_array($item->priority_label, ['critical', 'high'], true)
            && $item->hasInputRequest();
    }
}
