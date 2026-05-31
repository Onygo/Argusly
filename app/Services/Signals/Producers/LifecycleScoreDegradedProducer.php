<?php

namespace App\Services\Signals\Producers;

use App\Contracts\Signals\SignalProducer;
use App\Models\ContentLifecycleScore;
use App\Models\IntelligenceSignal;
use App\Services\Signals\SignalManager;

class LifecycleScoreDegradedProducer implements SignalProducer
{
    public function supports(object $event): bool
    {
        return $event instanceof ContentLifecycleScore
            && in_array($event->status, ['decaying', 'needs_refresh', 'critical'], true);
    }

    public function produce(object $event): ?IntelligenceSignal
    {
        /** @var ContentLifecycleScore $event */
        $asset = $event->contentAsset;
        $priority = match ($event->status) {
            'critical' => 'critical',
            'needs_refresh' => 'high',
            default => 'medium',
        };

        return app(SignalManager::class)->record($event->account, [
            'source' => 'content_lifecycle',
            'type' => 'content_opportunity',
            'category' => 'content',
            'priority' => $priority,
            'dedupe_key' => "lifecycle-degraded:{$event->content_asset_id}:{$event->status}",
            'title' => "Refresh recommended: {$asset->title}",
            'summary' => $event->reason ?? 'Content lifecycle score degraded.',
            'impact_score' => $event->refresh_priority,
            'confidence_score' => 84,
            'status' => 'new',
            'recommended_action' => 'Review the asset, run an audit, and plan a refresh based on the degraded lifecycle factors.',
            'payload' => [
                'content_asset_id' => $event->content_asset_id,
                'content_lifecycle_score_id' => $event->id,
                'event' => 'lifecycle_score_degraded',
                'health_score' => $event->health_score,
                'refresh_priority' => $event->refresh_priority,
                'status' => $event->status,
                'signals' => $event->signals,
            ],
        ], $event->brand);
    }
}
