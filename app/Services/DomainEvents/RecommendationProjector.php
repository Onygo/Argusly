<?php

namespace App\Services\DomainEvents;

use App\Contracts\DomainEventProjector;
use App\Models\DomainEvent;
use App\Models\IntelligenceSignal;
use App\Services\RecommendationEngineService;

class RecommendationProjector implements DomainEventProjector
{
    public function __construct(private readonly RecommendationEngineService $recommendations) {}

    public function project(DomainEvent $event): void
    {
        $signal = IntelligenceSignal::query()
            ->where('account_id', $event->account_id)
            ->where(function ($query) use ($event): void {
                $query->where('dedupe_key', "domain-event:{$event->uuid}:signal")
                    ->orWhere('payload->domain_event_uuid', $event->uuid);
            })
            ->first();

        if (! $signal) {
            return;
        }

        $this->recommendations->generateForSignal($signal);
    }
}
