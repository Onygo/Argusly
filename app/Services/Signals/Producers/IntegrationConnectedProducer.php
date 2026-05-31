<?php

namespace App\Services\Signals\Producers;

use App\Contracts\Signals\SignalProducer;
use App\Models\IntegrationConnection;
use App\Models\IntelligenceSignal;
use App\Services\Signals\SignalManager;

class IntegrationConnectedProducer implements SignalProducer
{
    public function supports(object $event): bool
    {
        return $event instanceof IntegrationConnection
            && $event->status === 'active'
            && $event->account_id !== null;
    }

    public function produce(object $event): ?IntelligenceSignal
    {
        /** @var IntegrationConnection $event */
        $event->loadMissing('integration');

        return app(SignalManager::class)->record($event->account, [
            'source' => 'integration',
            'type' => 'integration_connected',
            'category' => 'integration',
            'priority' => 'medium',
            'dedupe_key' => "integration-connected:{$event->id}",
            'title' => "Integration connected: {$event->integration->name}",
            'summary' => "The {$event->integration->name} connection is active and available in this tenant context.",
            'impact_score' => 50,
            'confidence_score' => 98,
            'status' => 'new',
            'recommended_action' => 'Confirm permissions, sharing scope and the workflows this integration should power.',
            'payload' => [
                'integration_connection_id' => $event->id,
                'integration_id' => $event->integration_id,
                'integration_key' => $event->integration->key,
                'connection_name' => $event->name,
            ],
        ], $event->brand);
    }
}
