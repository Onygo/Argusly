<?php

namespace App\Services\DomainEvents;

use App\Contracts\DomainEventProjector;
use App\Models\ActivityLog;
use App\Models\DomainEvent;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;

class ActivityLogProjector implements DomainEventProjector
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function project(DomainEvent $event): void
    {
        if (ActivityLog::query()
            ->where('event', $this->activityEventName($event))
            ->where('properties->domain_event_uuid', $event->uuid)
            ->exists()) {
            return;
        }

        $this->activity->log(
            event: $this->activityEventName($event),
            description: $this->description($event),
            account: $event->account,
            brand: $event->brand,
            user: $event->actor instanceof User ? $event->actor : null,
            subject: $event->subject instanceof Model ? $event->subject : null,
            properties: [
                'domain_event_uuid' => $event->uuid,
                'domain_event_type' => $event->event_type,
                'payload' => $event->payload,
            ],
        );
    }

    private function activityEventName(DomainEvent $event): string
    {
        return 'domain.'.str($event->event_type)->snake()->replace('_', '.');
    }

    private function description(DomainEvent $event): string
    {
        return match ($event->event_type) {
            'ContentAssetCreated' => 'Content asset created.',
            'ContentAssetPublished' => 'Content asset published.',
            'ContentAuditCompleted' => 'Content audit completed.',
            'LifecycleScoreCalculated' => 'Lifecycle score calculated.',
            'MentionCaptured' => 'Mention captured.',
            'VisibilityCheckCompleted' => 'Visibility check completed.',
            'VisibilityProviderRunCompleted' => 'Visibility provider run completed.',
            'VisibilityProviderRunFailed' => 'Visibility provider run failed.',
            'VisibilityRunScheduleExecuted' => 'Visibility run schedule executed.',
            'VisibilityRunScheduleFailed' => 'Visibility run schedule failed.',
            'SourceSyncCompleted' => 'Source sync completed.',
            'CampaignActivated' => 'Campaign activated.',
            'RecommendationAccepted' => 'Recommendation accepted.',
            'AgentRunCompleted' => 'Agent run completed.',
            'IntegrationConnected' => 'Integration connected.',
            'SocialPostCreated' => 'Social post created.',
            'SocialPostScheduled' => 'Social post scheduled.',
            'SocialPostPublished' => 'Social post published.',
            'SocialPostFailed' => 'Social post failed.',
            'SocialPostOverdue' => 'Social post is overdue.',
            'ContentAssetMissingSocialDistribution' => 'Content asset has no social distribution.',
            'CampaignMissingScheduledSocialPosts' => 'Campaign is missing scheduled social posts.',
            'ConnectorEventReceived' => 'Connector event received.',
            'CreditsLow' => 'Credits are running low.',
            default => "Domain event {$event->event_type} occurred.",
        };
    }
}
