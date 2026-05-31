<?php

namespace App\Services\Signals\Producers;

use App\Contracts\Signals\SignalProducer;
use App\Models\ContentAsset;
use App\Models\IntelligenceSignal;
use App\Services\Signals\SignalManager;

class ContentPublishedProducer implements SignalProducer
{
    public function supports(object $event): bool
    {
        return $event instanceof ContentAsset && $event->status === 'published';
    }

    public function produce(object $event): ?IntelligenceSignal
    {
        /** @var ContentAsset $event */
        return app(SignalManager::class)->record($event->account, [
            'source' => 'content_published',
            'type' => 'content_event',
            'category' => 'content',
            'priority' => 'medium',
            'dedupe_key' => "content-published:{$event->id}",
            'title' => "Content published: {$event->title}",
            'summary' => 'A content asset is now live and should be monitored for visibility, lifecycle health and downstream distribution.',
            'impact_score' => 55,
            'confidence_score' => 96,
            'status' => 'new',
            'recommended_action' => 'Review the published asset and schedule a lifecycle check after initial performance data arrives.',
            'payload' => [
                'content_asset_id' => $event->id,
                'published_at' => $event->published_at?->toDateTimeString(),
                'canonical_url' => $event->canonical_url,
            ],
        ], $event->brand);
    }
}
