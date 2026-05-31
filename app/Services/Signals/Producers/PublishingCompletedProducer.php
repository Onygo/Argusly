<?php

namespace App\Services\Signals\Producers;

use App\Contracts\Signals\SignalProducer;
use App\Models\IntelligenceSignal;
use App\Models\PublishingAction;
use App\Services\Signals\SignalManager;

class PublishingCompletedProducer implements SignalProducer
{
    public function supports(object $event): bool
    {
        return $event instanceof PublishingAction && $event->status === 'completed';
    }

    public function produce(object $event): ?IntelligenceSignal
    {
        /** @var PublishingAction $event */
        return app(SignalManager::class)->record($event->account, [
            'source' => 'content_publishing',
            'type' => 'integration_event',
            'category' => 'integration',
            'priority' => 'medium',
            'dedupe_key' => "publishing-completed:{$event->id}",
            'title' => "Publishing completed: {$event->contentAsset->title}",
            'summary' => 'A publishing workflow completed successfully.',
            'impact_score' => 45,
            'confidence_score' => 95,
            'status' => 'new',
            'recommended_action' => 'Review the published URL and monitor content lifecycle performance.',
            'payload' => [
                'content_asset_id' => $event->content_asset_id,
                'event' => 'publishing_completed',
                'publishing_action_id' => $event->id,
                'publishing_channel_id' => $event->publishing_channel_id,
                'action' => $event->action,
                'external_id' => $event->external_id,
                'external_url' => $event->external_url,
            ],
        ], $event->brand);
    }
}
