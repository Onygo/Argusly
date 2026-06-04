<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

#[Fillable([
    'account_id',
    'brand_id',
    'event_type',
    'subject_type',
    'subject_id',
    'actor_user_id',
    'payload',
    'occurred_at',
    'processed_at',
])]
class DomainEvent extends Model
{
    use HasFactory;

    public const TYPES = [
        'BrandCreated',
        'TopicCreated',
        'EntityCreated',
        'ContentAssetCreated',
        'ContentAssetPublished',
        'ContentAssetTranslationCreated',
        'ContentTranslationRequested',
        'ContentPublishingFailed',
        'ContentAuditCompleted',
        'LifecycleScoreCalculated',
        'MentionCaptured',
        'VisibilityCheckCompleted',
        'VisibilityProviderRunCompleted',
        'VisibilityProviderRunFailed',
        'VisibilityRunScheduleExecuted',
        'VisibilityRunScheduleFailed',
        'SourceSyncCompleted',
        'GA4SyncCompleted',
        'SearchConsoleSyncCompleted',
        'CampaignActivated',
        'PerformanceInsightDetected',
        'RecommendationAccepted',
        'RecommendationCreated',
        'RecommendationActionAccepted',
        'RecommendationActionExecuted',
        'RecommendationActionFailed',
        'MarketingTaskCreatedFromRecommendation',
        'BriefingDraftCreatedFromRecommendation',
        'NewsletterDraftCreatedFromRecommendation',
        'NewsletterSendQueued',
        'NewsletterSendStarted',
        'NewsletterSendCompleted',
        'NewsletterSendFailed',
        'AgentRunCompleted',
        'AgentTaskPlanned',
        'AgentTaskApproved',
        'AgentTaskQueued',
        'AgentTaskCompleted',
        'AgentTaskFailed',
        'ApprovalRequested',
        'ApprovalApproved',
        'ApprovalRejected',
        'ApprovalCancelled',
        'IntegrationConnected',
        'IntegrationDisconnected',
        'LinkedInProfileConnectionPrepared',
        'SocialProfileConnected',
        'SocialProfileShared',
        'SocialPostCreated',
        'SocialPostPrepared',
        'SocialPostApproved',
        'SocialPostScheduled',
        'SocialPostPublished',
        'SocialPostFailed',
        'SocialPostOverdue',
        'SocialPostVariantsGenerated',
        'SocialPostVariantSelected',
        'ContentAssetMissingSocialDistribution',
        'CampaignMissingScheduledSocialPosts',
        'ConnectorTokenCreated',
        'ConnectorTokenRevoked',
        'ConnectorTokenRotated',
        'ConnectorEventReceived',
        'CreditBalanceAdjusted',
        'CreditsLow',
        'CreditCostResolved',
        'CreditsConsumed',
        'CreditsRefunded',
        'CreditOverrideCreated',
        'LowCreditsDetected',
        'LlmRequestCompleted',
        'LlmRequestFailed',
        'LlmCreditsConsumed',
        'NarrativeCreated',
        'NarrativeObserved',
        'NarrativeObservationCaptured',
        'CampaignCreated',
        'NarrativeGapDetected',
    ];

    protected static function booted(): void
    {
        static::creating(function (DomainEvent $event): void {
            $event->uuid ??= (string) Str::uuid();
            $event->occurred_at ??= now();
        });
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
