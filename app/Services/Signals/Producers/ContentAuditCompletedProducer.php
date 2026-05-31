<?php

namespace App\Services\Signals\Producers;

use App\Contracts\Signals\SignalProducer;
use App\Models\ContentAudit;
use App\Models\IntelligenceSignal;
use App\Services\Signals\SignalManager;

class ContentAuditCompletedProducer implements SignalProducer
{
    public function supports(object $event): bool
    {
        return $event instanceof ContentAudit && $event->status === 'completed';
    }

    public function produce(object $event): ?IntelligenceSignal
    {
        /** @var ContentAudit $event */
        $asset = $event->contentAsset;
        $priority = $event->score < 50 ? 'high' : ($event->score < 70 ? 'medium' : 'low');

        return app(SignalManager::class)->record($event->account, [
            'source' => 'content_audit',
            'type' => 'content_audit_completed',
            'category' => 'visibility',
            'priority' => $priority,
            'dedupe_key' => "content-audit-completed:{$event->id}",
            'title' => "Audit completed: {$asset->title}",
            'summary' => $event->summary ?? 'Content audit completed.',
            'impact_score' => 100 - (int) $event->score,
            'confidence_score' => 88,
            'status' => 'new',
            'recommended_action' => $event->recommendations[0] ?? 'Review audit findings and apply the highest-impact content improvements.',
            'payload' => [
                'content_audit_id' => $event->id,
                'content_asset_id' => $event->content_asset_id,
                'score' => $event->score,
                'issues' => $event->issues,
                'recommendations' => $event->recommendations,
            ],
            'evidence_subject' => $event,
        ], $event->brand);
    }
}
