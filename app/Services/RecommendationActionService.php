<?php

namespace App\Services;

use App\Models\AnswerBlock;
use App\Models\Audience;
use App\Models\Briefing;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\IntegrationConnection;
use App\Models\MarketingObjective;
use App\Models\MarketingTask;
use App\Models\Newsletter;
use App\Models\Recommendation;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Models\VisibilityCheck;
use App\Services\SocialPublishing\SocialPublishingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class RecommendationActionService
{
    public function __construct(
        private readonly ContentAuditService $audits,
        private readonly ContentTranslationService $translations,
        private readonly SocialPublishingService $socialPublishing,
        private readonly VisibilityMonitoringService $visibility,
        private readonly DomainEventService $events,
        private readonly AgentTaskPlannerService $agentTasks,
    ) {}

    public function accept(Recommendation $recommendation, User $user): Recommendation
    {
        $this->assertTenantUser($recommendation, $user);
        $recommendation->accept($user);

        $this->events->recordForSubject('RecommendationAccepted', $recommendation->refresh(), $user, [
            'title' => $recommendation->title,
            'signal_id' => $recommendation->signal_id,
            'impact_score' => $recommendation->impact_score,
            'confidence_score' => $recommendation->confidence_score,
            'action_type' => $recommendation->action_type,
        ]);

        $this->events->recordForSubject('RecommendationActionAccepted', $recommendation, $user, [
            'action_type' => $recommendation->action_type,
            'action_payload' => $recommendation->action_payload,
        ]);

        $this->materializeAcceptedAction($recommendation->refresh(), $user);
        $this->agentTasks->planForRecommendation($recommendation->refresh(), $user);

        return $recommendation->refresh();
    }

    public function execute(Recommendation $recommendation, User $user): Recommendation
    {
        $this->assertTenantUser($recommendation, $user);

        if ($recommendation->action_type === null) {
            throw new InvalidArgumentException('Recommendation does not have an executable action.');
        }

        if ($recommendation->status !== 'accepted') {
            $this->accept($recommendation, $user);
            $recommendation = $recommendation->refresh();
        }

        try {
            $subject = DB::transaction(fn () => $this->executeAction($recommendation, $user));

            $recommendation->forceFill([
                'execution_status' => $this->queuedAction($recommendation->action_type) ? 'queued' : 'completed',
                'executed_at' => now(),
                'completed_at' => $this->queuedAction($recommendation->action_type) ? null : now(),
                'status' => $this->queuedAction($recommendation->action_type) ? 'accepted' : 'completed',
            ])->save();

            $this->events->recordForSubject('RecommendationActionExecuted', $recommendation->refresh(), $user, [
                'action_type' => $recommendation->action_type,
                'execution_status' => $recommendation->execution_status,
                'target_type' => $subject?->getMorphClass(),
                'target_id' => $subject?->getKey(),
            ]);
        } catch (Throwable $exception) {
            $recommendation->forceFill([
                'execution_status' => 'failed',
                'executed_at' => now(),
                'action_payload' => [
                    ...($recommendation->action_payload ?? []),
                    'last_error' => $exception->getMessage(),
                ],
            ])->save();

            $this->events->recordForSubject('RecommendationActionFailed', $recommendation->refresh(), $user, [
                'action_type' => $recommendation->action_type,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $recommendation->refresh();
    }

    private function executeAction(Recommendation $recommendation, User $user): ?Model
    {
        return match ($recommendation->action_type) {
            'run_content_audit' => $this->runContentAudit($recommendation, $user),
            'refresh_content' => $this->refreshContent($recommendation, $user),
            'create_answer_block' => $this->createAnswerBlock($recommendation, $user),
            'translate_content' => $this->translateContent($recommendation, $user),
            'create_social_post' => $this->createSocialPost($recommendation, $user),
            'schedule_social_post' => $this->scheduleSocialPost($recommendation, $user),
            'run_visibility_check' => $this->runVisibilityCheck($recommendation),
            'reconnect_integration' => $this->reconnectIntegration($recommendation),
            'create_campaign_task_plan',
            'attach_content_to_campaign',
            'attach_social_post_to_campaign',
            'create_objective_actions' => $this->materializeMarketingTask($recommendation, $user),
            'create_campaign_briefing' => $this->materializeBriefing($recommendation, $user),
            'create_newsletter_digest',
            'create_audience_newsletter' => $this->materializeNewsletter($recommendation, $user),
            'submit_newsletter_for_approval',
            'schedule_newsletter' => $this->materializeMarketingTask($recommendation, $user),
            default => throw new InvalidArgumentException("Unsupported recommendation action [{$recommendation->action_type}]."),
        };
    }

    private function materializeAcceptedAction(Recommendation $recommendation, User $user): ?Model
    {
        if ($recommendation->action_type === null) {
            return null;
        }

        return match ($recommendation->action_type) {
            'create_campaign_task_plan',
            'attach_content_to_campaign',
            'attach_social_post_to_campaign',
            'create_objective_actions' => $this->materializeMarketingTask($recommendation, $user),
            'create_campaign_briefing' => $this->materializeBriefing($recommendation, $user),
            'create_newsletter_digest',
            'create_audience_newsletter' => $this->materializeNewsletter($recommendation, $user),
            'submit_newsletter_for_approval',
            'schedule_newsletter' => $this->materializeMarketingTask($recommendation, $user),
            default => null,
        };
    }

    private function materializeMarketingTask(Recommendation $recommendation, User $user): MarketingTask
    {
        $payload = $recommendation->action_payload ?? [];
        $newsletter = isset($payload['newsletter_id']) ? $this->newsletter($recommendation, $payload['newsletter_id']) : null;
        $campaign = $this->campaign($recommendation, $payload['campaign_id'] ?? $newsletter?->campaign_id);
        $objective = $this->objective($recommendation, $payload['marketing_objective_id'] ?? null);

        $relatedType = $recommendation->getMorphClass();
        $relatedId = $recommendation->id;

        $task = MarketingTask::query()->updateOrCreate(
            [
                'account_id' => $recommendation->account_id,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
            ],
            [
                'brand_id' => $recommendation->brand_id,
                'campaign_id' => $campaign?->id,
                'marketing_objective_id' => $objective?->id,
                'title' => $this->taskTitle($recommendation),
                'description' => $recommendation->recommended_action ?: $recommendation->summary,
                'status' => 'todo',
                'priority' => $recommendation->impact_score >= 85 ? 'urgent' : ($recommendation->impact_score >= 70 ? 'high' : 'medium'),
                'assigned_to' => null,
                'created_by' => $user->id,
                'metadata' => [
                    'source' => 'recommendation_acceptance',
                    'recommendation_id' => $recommendation->id,
                    'action_type' => $recommendation->action_type,
                    'content_asset_id' => $payload['content_asset_id'] ?? null,
                    'social_post_id' => $payload['social_post_id'] ?? null,
                    'newsletter_id' => $payload['newsletter_id'] ?? null,
                    'audience_id' => $payload['audience_id'] ?? null,
                ],
            ],
        );

        $this->events->recordForSubject('MarketingTaskCreatedFromRecommendation', $task->refresh(), $user, [
            'recommendation_id' => $recommendation->id,
            'action_type' => $recommendation->action_type,
        ]);

        return $task->refresh();
    }

    private function materializeBriefing(Recommendation $recommendation, User $user): Briefing
    {
        $campaign = $this->campaign($recommendation, $recommendation->action_payload['campaign_id'] ?? null);

        $briefing = Briefing::query()->updateOrCreate(
            [
                'account_id' => $recommendation->account_id,
                'brand_id' => $recommendation->brand_id,
                'campaign_id' => $campaign?->id,
                'title' => $campaign ? "{$campaign->name} briefing" : $recommendation->title,
            ],
            [
                'objective' => $campaign?->objective ?: $recommendation->summary,
                'audience' => null,
                'tone_of_voice' => $recommendation->brand?->description,
                'key_message' => $recommendation->recommended_action,
                'channels' => ['blog', 'linkedin'],
                'languages' => [$recommendation->brand?->default_content_language ?? 'en'],
                'status' => 'draft',
                'created_by' => $user->id,
                'metadata' => [
                    'source' => 'recommendation_acceptance',
                    'recommendation_id' => $recommendation->id,
                    'generation_ready' => true,
                ],
            ],
        );

        $this->events->recordForSubject('BriefingDraftCreatedFromRecommendation', $briefing->refresh(), $user, [
            'recommendation_id' => $recommendation->id,
            'campaign_id' => $campaign?->id,
        ]);

        return $briefing->refresh();
    }

    private function materializeNewsletter(Recommendation $recommendation, User $user): Newsletter
    {
        $payload = $recommendation->action_payload ?? [];
        $campaign = $this->campaign($recommendation, $payload['campaign_id'] ?? null);
        $audience = $this->audience($recommendation, $payload['audience_id'] ?? null);
        $language = $recommendation->brand?->default_content_language ?? 'en';
        $title = $campaign
            ? "{$campaign->name} newsletter digest"
            : (($audience?->name ?? $recommendation->brand?->name).' newsletter');

        $newsletter = Newsletter::query()->updateOrCreate(
            [
                'account_id' => $recommendation->account_id,
                'brand_id' => $recommendation->brand_id,
                'campaign_id' => $campaign?->id,
                'title' => $title,
            ],
            [
                'subject' => $campaign ? "{$campaign->name} digest" : "Updates for {$audience?->name}",
                'preheader' => $recommendation->summary,
                'language' => $language,
                'status' => 'draft',
                'created_by' => $user->id,
                'metadata' => [
                    'source' => 'recommendation_acceptance',
                    'recommendation_id' => $recommendation->id,
                    'audience_id' => $audience?->id,
                    'generation_ready' => true,
                ],
            ],
        );

        $assetIds = collect($payload['content_asset_ids'] ?? [])
            ->filter()
            ->map(fn (mixed $id) => (int) $id)
            ->values();

        if ($assetIds->isNotEmpty() && ! $newsletter->sections()->exists()) {
            ContentAsset::query()
                ->whereIn('id', $assetIds)
                ->where('account_id', $recommendation->account_id)
                ->where('brand_id', $recommendation->brand_id)
                ->orderBy('id')
                ->get()
                ->each(function (ContentAsset $asset, int $index) use ($newsletter): void {
                    $newsletter->sections()->create([
                        'type' => 'content_asset',
                        'title' => $asset->title,
                        'body' => $asset->excerpt,
                        'content_asset_id' => $asset->id,
                        'position' => $index + 1,
                        'metadata' => ['source' => 'recommendation_acceptance'],
                    ]);
                });
        }

        $this->events->recordForSubject('NewsletterDraftCreatedFromRecommendation', $newsletter->refresh(), $user, [
            'recommendation_id' => $recommendation->id,
            'campaign_id' => $campaign?->id,
            'audience_id' => $audience?->id,
        ]);

        return $newsletter->refresh();
    }

    private function runContentAudit(Recommendation $recommendation, User $user): Model
    {
        return $this->audits->requestForContentAsset($this->contentAsset($recommendation), $user);
    }

    private function refreshContent(Recommendation $recommendation, User $user): Model
    {
        $asset = $this->contentAsset($recommendation);
        $asset->forceFill([
            'status' => in_array($asset->status, ['published', 'archived'], true) ? $asset->status : 'review',
            'last_refreshed_at' => now(),
            'updated_by' => $user->id,
            'metadata' => [
                ...($asset->metadata ?? []),
                'refresh_recommendation_id' => $recommendation->id,
                'refresh_requested_at' => now()->toIso8601String(),
            ],
        ])->save();

        return $asset->refresh();
    }

    private function createAnswerBlock(Recommendation $recommendation, User $user): Model
    {
        $asset = $this->contentAsset($recommendation);
        $payload = $recommendation->action_payload ?? [];

        return AnswerBlock::query()->create([
            'account_id' => $recommendation->account_id,
            'brand_id' => $recommendation->brand_id,
            'content_asset_id' => $asset->id,
            'question' => $payload['question'] ?? 'What should readers know about '.$asset->title.'?',
            'answer' => $payload['answer'] ?? str($asset->excerpt ?: strip_tags((string) $asset->body))->limit(500)->toString(),
            'type' => $payload['answer_type'] ?? 'summary',
            'status' => 'draft',
            'language' => $payload['language'] ?? $asset->language,
            'position' => $payload['position'] ?? 1,
            'metadata' => [
                'recommendation_id' => $recommendation->id,
                'created_by' => $user->id,
            ],
        ]);
    }

    private function translateContent(Recommendation $recommendation, User $user): ?Model
    {
        $translations = $this->translations->createTranslations(
            $this->contentAsset($recommendation),
            $user,
            $recommendation->action_payload['target_languages'] ?? $recommendation->signal?->payload['missing_languages'] ?? [],
        );

        return $translations->first();
    }

    private function createSocialPost(Recommendation $recommendation, User $user): Model
    {
        $asset = $this->contentAsset($recommendation);
        $profile = $this->socialProfile($recommendation);

        return $this->socialPublishing->prepare($recommendation->account, $recommendation->brand, $user, [
            'content_asset_id' => $asset->id,
            'campaign_id' => $recommendation->action_payload['campaign_id'] ?? $recommendation->signal?->payload['campaign_id'] ?? null,
            'social_profile_id' => $profile->id,
            'post_text' => $recommendation->action_payload['post_text'] ?? $asset->title."\n\n".str($asset->excerpt ?: $asset->body)->limit(220)->toString(),
            'language' => $recommendation->action_payload['language'] ?? $asset->language,
            'status' => 'draft',
            'metadata' => ['recommendation_id' => $recommendation->id],
        ]);
    }

    private function scheduleSocialPost(Recommendation $recommendation, User $user): Model
    {
        $post = isset($recommendation->action_payload['social_post_id'])
            ? SocialPost::query()->findOrFail($recommendation->action_payload['social_post_id'])
            : $this->createSocialPost($recommendation, $user);

        $this->assertSameTenant($recommendation, $post);

        return $this->socialPublishing->schedule($post, $user, $recommendation->action_payload['scheduled_at'] ?? now()->addDay());
    }

    private function runVisibilityCheck(Recommendation $recommendation): Model
    {
        $payload = $recommendation->action_payload ?? [];
        $check = $this->visibility->createCheck($recommendation->account, $recommendation->brand, [
            'provider' => $payload['provider'] ?? $recommendation->signal?->payload['provider'] ?? 'ChatGPT',
            'query' => $payload['query'] ?? $recommendation->signal?->payload['query'] ?? $recommendation->title,
            'brand' => $payload['brand'] ?? $recommendation->brand->name,
            'status' => 'active',
        ]);
        $this->visibility->queueCheck($check);

        return $check;
    }

    private function reconnectIntegration(Recommendation $recommendation): ?Model
    {
        $connectionId = $recommendation->action_payload['integration_connection_id'] ?? $recommendation->signal?->payload['integration_connection_id'] ?? null;

        if ($connectionId === null) {
            return null;
        }

        $connection = IntegrationConnection::query()->findOrFail($connectionId);
        $this->assertSameTenant($recommendation, $connection);
        $connection->forceFill(['status' => 'needs_reconnect'])->save();

        return $connection->refresh();
    }

    private function campaign(Recommendation $recommendation, mixed $campaignId): ?Campaign
    {
        if ($campaignId === null || $campaignId === '') {
            return null;
        }

        $campaign = Campaign::query()->findOrFail((int) $campaignId);
        $this->assertSameTenant($recommendation, $campaign);

        return $campaign;
    }

    private function objective(Recommendation $recommendation, mixed $objectiveId): ?MarketingObjective
    {
        if ($objectiveId === null || $objectiveId === '') {
            return null;
        }

        $objective = MarketingObjective::query()->findOrFail((int) $objectiveId);
        $this->assertSameTenant($recommendation, $objective);

        return $objective;
    }

    private function taskTitle(Recommendation $recommendation): string
    {
        return match ($recommendation->action_type) {
            'create_campaign_task_plan' => 'Build campaign task plan',
            'attach_content_to_campaign' => 'Attach content asset to campaign',
            'attach_social_post_to_campaign' => 'Attach social post to campaign',
            'create_objective_actions' => 'Create objective action plan',
            'submit_newsletter_for_approval' => 'Submit newsletter for approval',
            'schedule_newsletter' => 'Schedule newsletter',
            default => $recommendation->title,
        };
    }

    private function newsletter(Recommendation $recommendation, mixed $newsletterId): Newsletter
    {
        if ($newsletterId === null || $newsletterId === '') {
            throw new InvalidArgumentException('Recommendation action requires a newsletter.');
        }

        $newsletter = Newsletter::query()->findOrFail((int) $newsletterId);
        $this->assertSameTenant($recommendation, $newsletter);

        return $newsletter;
    }

    private function audience(Recommendation $recommendation, mixed $audienceId): ?Audience
    {
        if ($audienceId === null || $audienceId === '') {
            return null;
        }

        $audience = Audience::query()->findOrFail((int) $audienceId);
        $this->assertSameTenant($recommendation, $audience);

        return $audience;
    }

    private function contentAsset(Recommendation $recommendation): ContentAsset
    {
        $contentAssetId = $recommendation->action_payload['content_asset_id']
            ?? $recommendation->signal?->payload['content_asset_id']
            ?? null;

        if ($contentAssetId === null) {
            throw new InvalidArgumentException('Recommendation action requires a content asset.');
        }

        $asset = ContentAsset::query()->findOrFail($contentAssetId);
        $this->assertSameTenant($recommendation, $asset);

        return $asset;
    }

    private function socialProfile(Recommendation $recommendation): SocialProfile
    {
        $profile = isset($recommendation->action_payload['social_profile_id'])
            ? SocialProfile::query()->find($recommendation->action_payload['social_profile_id'])
            : SocialProfile::query()
                ->where('account_id', $recommendation->account_id)
                ->where(fn ($query) => $query->whereNull('brand_id')->orWhere('brand_id', $recommendation->brand_id))
                ->where('status', 'connected')
                ->first();

        if (! $profile) {
            throw new InvalidArgumentException('Recommendation action requires a connected social profile.');
        }

        $this->assertSameTenant($recommendation, $profile);

        return $profile;
    }

    private function assertTenantUser(Recommendation $recommendation, User $user): void
    {
        $hasAccount = $user->accounts()->whereKey($recommendation->account_id)->exists();

        if (! $hasAccount) {
            throw new InvalidArgumentException('User cannot act on recommendations outside their account.');
        }

        if ($recommendation->brand_id !== null && ! $user->brands()->whereKey($recommendation->brand_id)->exists()) {
            throw new InvalidArgumentException('User cannot act on recommendations outside their brand.');
        }
    }

    private function assertSameTenant(Recommendation $recommendation, Model $model): void
    {
        if ((int) $model->getAttribute('account_id') !== (int) $recommendation->account_id) {
            throw new InvalidArgumentException('Recommendation action target must belong to the same account.');
        }

        $brandId = $model->getAttribute('brand_id');

        if ($brandId !== null && (int) $brandId !== (int) $recommendation->brand_id) {
            throw new InvalidArgumentException('Recommendation action target must belong to the same brand.');
        }
    }

    private function queuedAction(string $actionType): bool
    {
        return in_array($actionType, ['run_content_audit', 'run_visibility_check'], true);
    }
}
