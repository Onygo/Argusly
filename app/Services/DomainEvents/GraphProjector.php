<?php

namespace App\Services\DomainEvents;

use App\Contracts\DomainEventProjector;
use App\Models\DomainEvent;
use App\Services\Graph\GraphProjectionService;

class GraphProjector implements DomainEventProjector
{
    public function __construct(private readonly GraphProjectionService $graph) {}

    public function project(DomainEvent $event): void
    {
        if (! in_array($event->event_type, $this->eventTypes(), true)) {
            return;
        }

        $subject = $event->subject;

        if ($subject) {
            $this->graph->project($subject);
        }
    }

    private function eventTypes(): array
    {
        return [
            'BrandCreated',
            'TopicCreated',
            'EntityCreated',
            'MentionCaptured',
            'NarrativeCreated',
            'NarrativeObserved',
            'NarrativeObservationCaptured',
            'CampaignCreated',
            'CampaignActivated',
            'RecommendationCreated',
            'ContentAssetCreated',
            'ContentAssetPublished',
            'SocialProfileConnected',
        ];
    }
}
