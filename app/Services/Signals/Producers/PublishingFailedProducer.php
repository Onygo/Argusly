<?php

namespace App\Services\Signals\Producers;

use App\Contracts\Signals\SignalProducer;
use App\Models\IntelligenceSignal;
use App\Models\PublishingAction;
use App\Services\Signals\SignalManager;

class PublishingFailedProducer implements SignalProducer
{
    public function supports(object $event): bool
    {
        return $event instanceof PublishingAction && $event->status === 'failed';
    }

    public function produce(object $event): ?IntelligenceSignal
    {
        /** @var PublishingAction $event */
        return app(SignalManager::class)->record($event->account, [
            'source' => 'content_publishing',
            'type' => 'publishing_failed',
            'category' => 'system',
            'priority' => 'critical',
            'dedupe_key' => "publishing-failed:{$event->id}",
            'title' => "Publishing failed: {$event->contentAsset->title}",
            'summary' => $event->error_message ?: 'A publishing workflow failed before completion.',
            'impact_score' => 92,
            'confidence_score' => 98,
            'status' => 'new',
            'recommended_action' => 'Review the publishing error, connection health and channel configuration before retrying.',
            'payload' => [
                'content_asset_id' => $event->content_asset_id,
                'publishing_action_id' => $event->id,
                'publishing_channel_id' => $event->publishing_channel_id,
                'action' => $event->action,
                'error_message' => $event->error_message,
            ],
        ], $event->brand);
    }
}
