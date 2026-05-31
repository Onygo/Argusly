<?php

namespace App\Services\Signals\Producers;

use App\Contracts\Signals\SignalProducer;
use App\Models\GeneratedAsset;
use App\Models\IntelligenceSignal;
use App\Services\Signals\SignalManager;

class GenerationCompletedProducer implements SignalProducer
{
    public function supports(object $event): bool
    {
        return $event instanceof GeneratedAsset && $event->status === 'completed';
    }

    public function produce(object $event): ?IntelligenceSignal
    {
        /** @var GeneratedAsset $event */
        return app(SignalManager::class)->record($event->account, [
            'source' => 'content_generation',
            'type' => 'generation_completed',
            'category' => 'content',
            'priority' => 'low',
            'dedupe_key' => "generation-completed:{$event->id}",
            'title' => "Generation completed: {$event->title}",
            'summary' => 'Generated content is ready for editorial review and reuse.',
            'impact_score' => 35,
            'confidence_score' => 96,
            'status' => 'new',
            'recommended_action' => 'Review the generated output and merge the useful parts into the source content workflow.',
            'payload' => [
                'generated_asset_id' => $event->id,
                'content_asset_id' => $event->content_asset_id,
                'type' => $event->type,
                'provider' => $event->provider,
                'model' => $event->model,
            ],
        ], $event->brand);
    }
}
