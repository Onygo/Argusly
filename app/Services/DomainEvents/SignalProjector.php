<?php

namespace App\Services\DomainEvents;

use App\Contracts\DomainEventProjector;
use App\Models\DomainEvent;
use App\Services\Signals\SignalManager;

class SignalProjector implements DomainEventProjector
{
    public function __construct(private readonly SignalManager $signals) {}

    public function project(DomainEvent $event): void
    {
        $attributes = $this->attributesFor($event);

        if ($attributes === null) {
            return;
        }

        $dedupeKey = $attributes['_dedupe_key'] ?? "domain-event:{$event->uuid}:signal";
        unset($attributes['_dedupe_key']);

        $this->signals->record($event->account, [
            ...$attributes,
            'source' => 'domain_event',
            'dedupe_key' => $dedupeKey,
            'status' => 'new',
            'payload' => [
                ...($attributes['payload'] ?? []),
                'domain_event_uuid' => $event->uuid,
                'domain_event_type' => $event->event_type,
                'domain_event_payload' => $event->payload,
            ],
        ], $event->brand, generateRecommendations: false);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function attributesFor(DomainEvent $event): ?array
    {
        $payload = $event->payload ?? [];

        return match ($event->event_type) {
            'ContentAssetPublished' => [
                'type' => 'content_event',
                'category' => 'content',
                'priority' => 'medium',
                'title' => 'Content published',
                'summary' => 'A content asset is now live and ready for monitoring and distribution follow-up.',
                'impact_score' => 55,
                'confidence_score' => 96,
                'recommended_action' => 'Review the published asset and schedule follow-up monitoring.',
                'payload' => $payload,
            ],
            'ContentPublishingFailed' => [
                'type' => 'publishing_failed',
                'category' => 'system',
                'priority' => 'critical',
                'title' => 'Publishing failed',
                'summary' => 'A connector publishing workflow reported a failure.',
                'impact_score' => 92,
                'confidence_score' => 98,
                'recommended_action' => 'Review the publishing error, connector health and channel configuration before retrying.',
                'payload' => $payload,
            ],
            'ContentAuditCompleted' => [
                'type' => 'content_audit_completed',
                'category' => 'visibility',
                'priority' => ((int) ($payload['score'] ?? 100)) < 50 ? 'high' : (((int) ($payload['score'] ?? 100)) < 70 ? 'medium' : 'low'),
                'title' => 'Content audit completed',
                'summary' => 'A content audit completed and produced scoring inputs for recommendations.',
                'impact_score' => 100 - (int) ($payload['score'] ?? 50),
                'confidence_score' => 88,
                'recommended_action' => 'Review the audit findings and apply the highest-impact improvements.',
                'payload' => $payload,
            ],
            'LifecycleScoreCalculated' => [
                'type' => 'content_opportunity',
                'category' => 'content',
                'priority' => ((int) ($payload['health_score'] ?? 100)) < 40 ? 'critical' : (((int) ($payload['health_score'] ?? 100)) < 65 ? 'high' : 'low'),
                'title' => 'Lifecycle score calculated',
                'summary' => 'A content lifecycle score was calculated from freshness, performance and visibility inputs.',
                'impact_score' => $payload['refresh_priority'] ?? null,
                'confidence_score' => 84,
                'recommended_action' => 'Use the lifecycle score to decide whether the asset needs monitoring, audit or refresh work.',
                'payload' => $payload,
            ],
            'MentionCaptured' => [
                'type' => 'visibility_change',
                'category' => 'visibility',
                'priority' => 'medium',
                'title' => 'Mention captured',
                'summary' => 'A mention was captured from a configured source or manual capture flow.',
                'impact_score' => $payload['impact_score'] ?? 50,
                'confidence_score' => 85,
                'recommended_action' => 'Review the mention evidence, sentiment and source context.',
                'payload' => $payload,
            ],
            'VisibilityCheckCompleted' => [
                'type' => 'visibility_change',
                'category' => 'visibility',
                'priority' => ((int) ($payload['score'] ?? 0)) < 40 ? 'high' : 'medium',
                'title' => 'Visibility check completed',
                'summary' => 'A visibility result was captured for a provider and query.',
                'impact_score' => $payload['score'] ?? null,
                'confidence_score' => 90,
                'recommended_action' => 'Review provider visibility and compare it with recent trend data.',
                'payload' => $payload,
            ],
            'VisibilityProviderRunCompleted' => [
                'type' => 'visibility_change',
                'category' => 'visibility',
                'priority' => ((int) ($payload['visibility_score'] ?? 0)) < 40 ? 'high' : 'medium',
                'title' => 'AI visibility provider run completed',
                'summary' => 'A provider adapter completed a normalized AI visibility answer run.',
                'impact_score' => $payload['visibility_score'] ?? null,
                'confidence_score' => 90,
                'recommended_action' => 'Review the normalized answer, citations and extracted entities.',
                'payload' => $payload,
            ],
            'VisibilityProviderRunFailed' => [
                'type' => 'visibility_change',
                'category' => 'visibility',
                'priority' => 'high',
                'title' => 'AI visibility provider run failed',
                'summary' => 'A provider adapter failed before completing an AI visibility run.',
                'impact_score' => 80,
                'confidence_score' => 90,
                'recommended_action' => 'Review provider configuration and retry the visibility run.',
                'payload' => $payload,
            ],
            'VisibilityRunScheduleExecuted' => [
                'type' => 'visibility_change',
                'category' => 'visibility',
                'priority' => 'low',
                'title' => 'AI visibility schedule ran',
                'summary' => 'A scheduled AI visibility prompt run completed.',
                'impact_score' => $payload['visibility_score'] ?? 40,
                'confidence_score' => 90,
                'recommended_action' => 'Review the latest provider run for answer, citation and entity changes.',
                'payload' => $payload,
            ],
            'VisibilityRunScheduleFailed' => [
                'type' => 'visibility_change',
                'category' => 'visibility',
                'priority' => 'high',
                'title' => 'AI visibility schedule failed',
                'summary' => 'A scheduled AI visibility prompt run failed.',
                'impact_score' => 80,
                'confidence_score' => 90,
                'recommended_action' => 'Review schedule settings, provider adapter health and credit balance.',
                'payload' => $payload,
            ],
            'SourceSyncCompleted' => [
                'type' => 'integration_event',
                'category' => 'integration',
                'priority' => 'low',
                'title' => 'Source sync completed',
                'summary' => 'A source ingestion run completed and updated the source sync history.',
                'impact_score' => 35,
                'confidence_score' => 95,
                'recommended_action' => 'Review records found and downstream evidence coverage.',
                'payload' => $payload,
            ],
            'CampaignActivated' => [
                'type' => 'content_event',
                'category' => 'content',
                'priority' => 'medium',
                'title' => 'Campaign activated',
                'summary' => 'A campaign moved into active status.',
                'impact_score' => 60,
                'confidence_score' => 95,
                'recommended_action' => 'Confirm campaign assets, topics and signals are aligned before execution.',
                'payload' => $payload,
            ],
            'RecommendationAccepted' => [
                'type' => 'agent_recommendation',
                'category' => 'system',
                'priority' => 'medium',
                'title' => 'Recommendation accepted',
                'summary' => 'A recommendation was accepted and is ready for downstream execution.',
                'impact_score' => $payload['impact_score'] ?? 50,
                'confidence_score' => $payload['confidence_score'] ?? 85,
                'recommended_action' => 'Prepare agent or manual execution tasks for the accepted recommendation.',
                'payload' => $payload,
            ],
            'AgentRunCompleted' => [
                'type' => 'agent_recommendation',
                'category' => 'system',
                'priority' => 'low',
                'title' => 'Agent run completed',
                'summary' => 'An agent run completed and produced a result payload.',
                'impact_score' => 40,
                'confidence_score' => 90,
                'recommended_action' => 'Review the run output and decide whether follow-up action is needed.',
                'payload' => $payload,
            ],
            'IntegrationConnected' => [
                'type' => 'integration_connected',
                'category' => 'integration',
                'priority' => 'medium',
                'title' => 'Integration connected',
                'summary' => 'An integration credential is active in this tenant context.',
                'impact_score' => 50,
                'confidence_score' => 98,
                'recommended_action' => 'Confirm permissions, sharing scope and enabled workflows.',
                'payload' => $payload,
            ],
            'SocialPostCreated' => [
                '_dedupe_key' => "social-post-created:{$payload['social_post_id']}",
                'type' => 'content_event',
                'category' => 'social',
                'priority' => 'low',
                'title' => 'Social post created',
                'summary' => 'A social post draft was created for a connected social profile.',
                'impact_score' => 35,
                'confidence_score' => 95,
                'recommended_action' => 'Review the draft and schedule it when the content is ready.',
                'payload' => $payload,
            ],
            'SocialPostScheduled' => [
                '_dedupe_key' => "social-post-scheduled:{$payload['social_post_id']}",
                'type' => 'content_event',
                'category' => 'social',
                'priority' => 'medium',
                'title' => 'Social post scheduled',
                'summary' => 'A social post has been scheduled for distribution.',
                'impact_score' => 45,
                'confidence_score' => 96,
                'recommended_action' => 'Monitor the scheduled post and confirm the profile remains connected.',
                'payload' => $payload,
            ],
            'SocialPostPublished' => [
                '_dedupe_key' => "social-post-published:{$payload['social_post_id']}",
                'type' => 'publishing_completed',
                'category' => 'social',
                'priority' => 'medium',
                'title' => 'Social post published',
                'summary' => 'A social post was published through the configured provider.',
                'impact_score' => 45,
                'confidence_score' => 95,
                'recommended_action' => 'Monitor social engagement and use the result to refine future distribution.',
                'payload' => $payload,
            ],
            'SocialPostFailed' => [
                '_dedupe_key' => "social-post-failed:{$payload['social_post_id']}",
                'type' => 'publishing_failed',
                'category' => 'social',
                'priority' => 'critical',
                'title' => (($payload['provider'] ?? null) === 'linkedin' ? 'LinkedIn' : str($payload['provider'] ?? 'Social')->headline()).' post failed to publish',
                'summary' => $payload['error_message'] ?? 'A social post failed before it could be published.',
                'impact_score' => 92,
                'confidence_score' => 98,
                'recommended_action' => 'Reconnect the social profile and retry the post.',
                'payload' => $payload,
            ],
            'SocialPostOverdue' => [
                '_dedupe_key' => "social-post-overdue:{$payload['social_post_id']}",
                'type' => 'content_opportunity',
                'category' => 'social',
                'priority' => 'high',
                'title' => 'Scheduled social post is overdue',
                'summary' => 'A scheduled social post has not been published after its planned time.',
                'impact_score' => 80,
                'confidence_score' => 92,
                'recommended_action' => 'Review the schedule, profile permissions and queue health before retrying.',
                'payload' => $payload,
            ],
            'ContentAssetMissingSocialDistribution' => [
                '_dedupe_key' => "content-asset-missing-social:{$payload['content_asset_id']}",
                'type' => 'content_opportunity',
                'category' => 'social',
                'priority' => 'medium',
                'title' => 'Content asset has no social distribution',
                'summary' => 'A content asset has no linked or scheduled social posts.',
                'impact_score' => 62,
                'confidence_score' => 90,
                'recommended_action' => 'Create a LinkedIn post from this article.',
                'payload' => $payload,
            ],
            'CampaignMissingScheduledSocialPosts' => [
                '_dedupe_key' => "campaign-missing-social-schedule:{$payload['campaign_id']}",
                'type' => 'content_opportunity',
                'category' => 'social',
                'priority' => 'high',
                'title' => 'Campaign has content but no scheduled social posts',
                'summary' => 'A campaign has content assets but no scheduled social distribution.',
                'impact_score' => 76,
                'confidence_score' => 90,
                'recommended_action' => 'Schedule social distribution for the campaign.',
                'payload' => $payload,
            ],
            'CreditsLow' => [
                'type' => 'credits_low',
                'category' => 'billing',
                'priority' => ((int) ($payload['balance_after'] ?? 100)) <= 0 ? 'critical' : 'high',
                'title' => 'Credits are running low',
                'summary' => 'The account credit balance is low enough to affect product workflows.',
                'impact_score' => ((int) ($payload['balance_after'] ?? 100)) <= 0 ? 95 : 75,
                'confidence_score' => 99,
                'recommended_action' => 'Review credit usage and top up before workflows are blocked.',
                'payload' => $payload,
            ],
            default => null,
        };
    }
}
