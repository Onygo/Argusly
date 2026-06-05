<?php

namespace App\Services\OpportunityIntelligence;

use App\Models\OpportunitySignal;
use App\Models\Workspace;

class OpportunitySignalIngestor
{
    public function ingest(Workspace $workspace, OpportunitySignalPayload $payload): OpportunitySignal
    {
        $hash = $this->hash($workspace, $payload);

        return OpportunitySignal::query()->updateOrCreate(
            [
                'workspace_id' => (string) $workspace->id,
                'dedupe_hash' => $hash,
            ],
            [
                'organization_id' => $workspace->organization_id,
                'client_site_id' => $payload->clientSiteId,
                'content_id' => $payload->contentId,
                'content_cluster_id' => $payload->contentClusterId,
                'campaign_id' => $payload->campaignId,
                'source' => $payload->source->value,
                'category' => $payload->category?->value,
                'topic' => $payload->topic,
                'entity' => $payload->entity,
                'signal_strength' => max(0, min(100, $payload->signalStrength)),
                'confidence' => max(0, min(100, $payload->confidence)),
                'observed_at' => $payload->observedAt ?? now(),
                'metrics' => $payload->metrics,
                'evidence' => $payload->evidence,
                'metadata' => $payload->metadata,
            ]
        );
    }

    private function hash(Workspace $workspace, OpportunitySignalPayload $payload): string
    {
        return hash('sha256', implode('|', [
            (string) $workspace->id,
            $payload->source->value,
            $payload->category?->value ?? '',
            strtolower(trim((string) $payload->topic)),
            strtolower(trim((string) $payload->entity)),
            (string) $payload->contentId,
            (string) $payload->contentClusterId,
            (string) $payload->campaignId,
            ($payload->observedAt ?? now())->format('Y-m-d'),
        ]));
    }
}
